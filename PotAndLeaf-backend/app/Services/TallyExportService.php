<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\SupplierPayment;
use Illuminate\Support\Carbon;

/**
 * Tally-compatible export (proposal module 08: "Tally-compatible export";
 * live Tally integration is out of scope).
 *
 * Builds a Tally "Import Data" XML file with:
 *   - ledger masters for every party and the fixed accounts used below, and
 *   - Sales, Purchase, Credit Note, Debit Note, Receipt and Payment vouchers
 *     for one company and a date range.
 *
 * Tally convention: a debit line has ISDEEMEDPOSITIVE=Yes and a negative
 * AMOUNT; a credit line has ISDEEMEDPOSITIVE=No and a positive AMOUNT.
 * Every voucher balances to zero (checked in tests).
 */
class TallyExportService
{
    public const SALES = 'Sales';
    public const PURCHASE = 'Purchase';
    public const OUTPUT_CGST = 'Output CGST';
    public const OUTPUT_SGST = 'Output SGST';
    public const OUTPUT_IGST = 'Output IGST';
    public const INPUT_CGST = 'Input CGST';
    public const INPUT_SGST = 'Input SGST';
    public const INPUT_IGST = 'Input IGST';
    public const ROUND_OFF = 'Round Off';
    public const LOYALTY = 'Loyalty Discount';
    public const FREIGHT = 'Freight & Loading Inward';
    public const FREIGHT_PAYABLE = 'Freight Payable';
    public const CASH = 'Cash';
    public const BANK = 'Bank';
    public const WALK_IN = 'Cash Sales (Walk-in)';

    /** Fixed ledgers and their Tally groups. */
    private const FIXED = [
        self::SALES           => 'Sales Accounts',
        self::PURCHASE        => 'Purchase Accounts',
        self::OUTPUT_CGST     => 'Duties & Taxes',
        self::OUTPUT_SGST     => 'Duties & Taxes',
        self::OUTPUT_IGST     => 'Duties & Taxes',
        self::INPUT_CGST      => 'Duties & Taxes',
        self::INPUT_SGST      => 'Duties & Taxes',
        self::INPUT_IGST      => 'Duties & Taxes',
        self::ROUND_OFF       => 'Indirect Expenses',
        self::LOYALTY         => 'Indirect Expenses',
        self::FREIGHT         => 'Direct Expenses',
        self::FREIGHT_PAYABLE => 'Current Liabilities',
        self::CASH            => 'Cash-in-Hand',
        self::BANK            => 'Bank Accounts',
        self::WALK_IN         => 'Sundry Debtors',
    ];

    /**
     * @return array{xml: string, vouchers: list<array{type: string, number: string, date: string, lines: list<array{ledger: string, amount: float}>}>}
     *   amount > 0 = debit, < 0 = credit (before Tally sign conversion)
     */
    public function build(int|string $companyId, string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $company = Company::findOrFail($companyId);

        $vouchers = [];
        $debtors = [];
        $creditors = [];

        Sale::forCompany($companyId)->where('status', 'confirmed')->where('bill_kind', '!=', 'proforma')
            ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
            ->with(['items', 'customer:id,name'])->orderBy('sale_date')->orderBy('sale_no')
            ->each(function (Sale $s) use (&$vouchers, &$debtors) {
                $party = $s->customer?->name ?: self::WALK_IN;
                $debtors[$party] = true;
                $tax = $this->taxSplit($s->items);
                $due = round((float) $s->grand_total - (float) $s->loyalty_discount, 2);
                $vouchers[] = $this->voucher('Sales', $s->sale_no, $s->sale_date, "Sale {$s->sale_no}", [
                    [$party, $due],
                    [self::LOYALTY, (float) $s->loyalty_discount],
                    [self::SALES, -(float) $s->subtotal],
                    [self::OUTPUT_CGST, -$tax['cgst']],
                    [self::OUTPUT_SGST, -$tax['sgst']],
                    [self::OUTPUT_IGST, -$tax['igst']],
                    [self::ROUND_OFF, -(float) $s->round_off],
                ]);
            });

        SalesReturn::forCompany($companyId)->where('status', 'confirmed')
            ->whereDate('return_date', '>=', $from)->whereDate('return_date', '<=', $to)
            ->with(['items', 'customer:id,name'])->orderBy('return_date')->orderBy('return_no')
            ->each(function (SalesReturn $r) use (&$vouchers, &$debtors) {
                $party = $r->customer?->name ?: self::WALK_IN;
                $debtors[$party] = true;
                $tax = $this->taxSplit($r->items);
                $vouchers[] = $this->voucher('Credit Note', $r->return_no, $r->return_date, "Sales return {$r->return_no}", [
                    [self::SALES, (float) $r->subtotal],
                    [self::OUTPUT_CGST, $tax['cgst']],
                    [self::OUTPUT_SGST, $tax['sgst']],
                    [self::OUTPUT_IGST, $tax['igst']],
                    [self::ROUND_OFF, (float) $r->round_off],
                    [$party, -(float) $r->grand_total],
                ]);
            });

        Purchase::forCompany($companyId)->where('status', 'confirmed')
            ->whereDate('purchase_date', '>=', $from)->whereDate('purchase_date', '<=', $to)
            ->with(['items', 'supplier:id,name'])->orderBy('purchase_date')->orderBy('purchase_no')
            ->each(function (Purchase $p) use (&$vouchers, &$creditors) {
                $party = $p->supplier?->name ?: 'Unknown Supplier';
                $creditors[$party] = true;
                $tax = $this->taxSplit($p->items);
                $payable = $p->supplierPayable();
                $vouchers[] = $this->voucher('Purchase', $p->purchase_no, $p->purchase_date, "Purchase {$p->purchase_no} (supplier bill {$p->invoice_no})", [
                    [self::PURCHASE, (float) $p->subtotal],
                    [self::INPUT_CGST, $tax['cgst']],
                    [self::INPUT_SGST, $tax['sgst']],
                    [self::INPUT_IGST, $tax['igst']],
                    [self::FREIGHT, (float) $p->landed_cost_total],
                    [$party, -$payable],
                    // Freight paid to someone other than the supplier.
                    [self::FREIGHT_PAYABLE, -round((float) $p->grand_total - $payable, 2)],
                ]);
            });

        PurchaseReturn::forCompany($companyId)->where('status', 'confirmed')
            ->whereDate('return_date', '>=', $from)->whereDate('return_date', '<=', $to)
            ->with(['items', 'supplier:id,name'])->orderBy('return_date')->orderBy('return_no')
            ->each(function (PurchaseReturn $r) use (&$vouchers, &$creditors) {
                $party = $r->supplier?->name ?: 'Unknown Supplier';
                $creditors[$party] = true;
                $tax = $this->taxSplit($r->items);
                $vouchers[] = $this->voucher('Debit Note', $r->return_no, $r->return_date, "Purchase return {$r->return_no}", [
                    [$party, (float) $r->grand_total],
                    [self::PURCHASE, -(float) $r->subtotal],
                    [self::INPUT_CGST, -$tax['cgst']],
                    [self::INPUT_SGST, -$tax['sgst']],
                    [self::INPUT_IGST, -$tax['igst']],
                ]);
            });

        // Money actually received / paid (advances applied later move no cash).
        CustomerReceipt::forCompany($companyId)->where('applied_from_advance', false)
            ->whereDate('receipt_date', '>=', $from)->whereDate('receipt_date', '<=', $to)
            ->with('customer:id,name')->orderBy('receipt_date')->orderBy('receipt_no')
            ->each(function (CustomerReceipt $r) use (&$vouchers, &$debtors) {
                $party = $r->customer?->name ?: self::WALK_IN;
                $debtors[$party] = true;
                $vouchers[] = $this->voucher('Receipt', $r->receipt_no, $r->receipt_date, $r->notes ?: "Receipt {$r->receipt_no}", [
                    [$this->moneyLedger($r->mode), (float) $r->amount],
                    [$party, -(float) $r->amount],
                ]);
            });

        SupplierPayment::forCompany($companyId)->where('applied_from_advance', false)
            ->whereDate('payment_date', '>=', $from)->whereDate('payment_date', '<=', $to)
            ->with('supplier:id,name')->orderBy('payment_date')->orderBy('payment_no')
            ->each(function (SupplierPayment $p) use (&$vouchers, &$creditors) {
                $party = $p->supplier?->name ?: 'Unknown Supplier';
                $creditors[$party] = true;
                $vouchers[] = $this->voucher('Payment', $p->payment_no, $p->payment_date, $p->notes ?: "Payment {$p->payment_no}", [
                    [$party, (float) $p->amount],
                    [$this->moneyLedger($p->mode), -(float) $p->amount],
                ]);
            });

        $ledgers = self::FIXED;
        foreach (array_keys($debtors) as $name) {
            $ledgers[$name] = 'Sundry Debtors';
        }
        foreach (array_keys($creditors) as $name) {
            $ledgers[$name] ??= 'Sundry Creditors';
        }

        return [
            'xml'      => $this->xml($company->name, $ledgers, $vouchers),
            'vouchers' => $vouchers,
        ];
    }

    /** @return array{cgst: float, sgst: float, igst: float} */
    private function taxSplit($items): array
    {
        return [
            'cgst' => round((float) $items->sum('cgst_amount'), 2),
            'sgst' => round((float) $items->sum('sgst_amount'), 2),
            'igst' => round((float) $items->sum('igst_amount'), 2),
        ];
    }

    private function moneyLedger(?string $mode): string
    {
        return ($mode ?? 'cash') === 'cash' ? self::CASH : self::BANK;
    }

    /**
     * @param  list<array{0: string, 1: float}>  $lines  [ledger, amount (+debit / −credit)]
     */
    private function voucher(string $type, string $number, $date, string $narration, array $lines): array
    {
        $kept = [];
        foreach ($lines as [$ledger, $amount]) {
            $amount = round((float) $amount, 2);
            if (abs($amount) >= 0.005) {
                $kept[] = ['ledger' => $ledger, 'amount' => $amount];
            }
        }

        return [
            'type'      => $type,
            'number'    => $number,
            'date'      => Carbon::parse($date)->toDateString(),
            'narration' => $narration,
            'lines'     => $kept,
        ];
    }

    /** @param array<string, string> $ledgers name => group */
    private function xml(string $companyName, array $ledgers, array $vouchers): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = [];
        $out[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $out[] = '<ENVELOPE>';
        $out[] = '<HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER>';
        $out[] = '<BODY><IMPORTDATA>';
        $out[] = '<REQUESTDESC><REPORTNAME>All Masters</REPORTNAME><STATICVARIABLES><SVCURRENTCOMPANY>'.$e($companyName).'</SVCURRENTCOMPANY></STATICVARIABLES></REQUESTDESC>';
        $out[] = '<REQUESTDATA>';

        ksort($ledgers);
        foreach ($ledgers as $name => $group) {
            $out[] = '<TALLYMESSAGE xmlns:UDF="TallyUDF"><LEDGER NAME="'.$e($name).'" ACTION="Create">'
                .'<NAME.LIST><NAME>'.$e($name).'</NAME></NAME.LIST>'
                .'<PARENT>'.$e($group).'</PARENT>'
                .(in_array($group, ['Sundry Debtors', 'Sundry Creditors'], true) ? '<ISBILLWISEON>Yes</ISBILLWISEON>' : '')
                .'</LEDGER></TALLYMESSAGE>';
        }

        foreach ($vouchers as $v) {
            $out[] = '<TALLYMESSAGE xmlns:UDF="TallyUDF">';
            $out[] = '<VOUCHER VCHTYPE="'.$e($v['type']).'" ACTION="Create">';
            $out[] = '<DATE>'.str_replace('-', '', $v['date']).'</DATE>';
            $out[] = '<VOUCHERTYPENAME>'.$e($v['type']).'</VOUCHERTYPENAME>';
            $out[] = '<VOUCHERNUMBER>'.$e($v['number']).'</VOUCHERNUMBER>';
            $out[] = '<NARRATION>'.$e($v['narration']).'</NARRATION>';
            foreach ($v['lines'] as $line) {
                $debit = $line['amount'] > 0;
                $out[] = '<ALLLEDGERENTRIES.LIST>'
                    .'<LEDGERNAME>'.$e($line['ledger']).'</LEDGERNAME>'
                    .'<ISDEEMEDPOSITIVE>'.($debit ? 'Yes' : 'No').'</ISDEEMEDPOSITIVE>'
                    .'<AMOUNT>'.number_format($debit ? -$line['amount'] : abs($line['amount']), 2, '.', '').'</AMOUNT>'
                    .'</ALLLEDGERENTRIES.LIST>';
            }
            $out[] = '</VOUCHER>';
            $out[] = '</TALLYMESSAGE>';
        }

        $out[] = '</REQUESTDATA>';
        $out[] = '</IMPORTDATA></BODY>';
        $out[] = '</ENVELOPE>';

        return implode("\n", $out)."\n";
    }
}
