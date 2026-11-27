<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerRefund;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\SupplierRefund;
use App\Support\Numbering\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money for goods that came back.
 *
 * A confirmed return first reduces what is still owed on the bill. Whatever is left is either kept
 * as advance (the default, usable on a later bill or purchase) or really paid back. Money that
 * really moves is a REFUND record: it is posted to the Cash Book / Bank Book and shows on the
 * customer / supplier statement. An amount kept as advance can be marked "given" later.
 */
class ReturnRefundService
{
    public const MODES = ['cash', 'upi', 'card', 'bank', 'cheque'];

    public function __construct(private readonly ActivityLogService $activity) {}

    // ---- sales return (we pay the customer) --------------------------------------------------

    /** Called while a sales return is confirmed: $excess is the part of the credit not used against dues. */
    public function settleSalesReturn(SalesReturn $return, ?Customer $customer, float $excess, ?int $userId = null): void
    {
        $excess = round($excess, 2);
        if ($excess <= 0.005) {
            return;
        }

        $mode = $return->refund_mode ?: 'advance';
        if ($mode === 'advance' && $customer) {
            $customer->advance_balance = round((float) $customer->advance_balance + $excess, 2);
            $customer->save();
            $return->update(['advance_credit' => $excess]);

            return;
        }

        // Paid back straight away (also the only choice when no customer is on the return).
        $this->makeCustomerRefund($return, $customer?->id, $excess, in_array($mode, self::MODES, true) ? $mode : 'cash', now()->toDateString(), null, 'Refunded with return '.$return->return_no, false, $userId);
    }

    /**
     * Mark money kept as customer advance as really given back.
     *
     * @param  array{amount?: float|string|null, mode: string, refund_date?: ?string, reference?: ?string, notes?: ?string}  $data
     */
    public function refundSalesReturn(SalesReturn $return, array $data, ?int $userId = null): CustomerRefund
    {
        return DB::transaction(function () use ($return, $data, $userId) {
            $return = SalesReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== 'confirmed') {
                throw ValidationException::withMessages(['status' => 'Only a confirmed return can be refunded.']);
            }

            $refundable = round((float) $return->advance_credit - (float) $return->advance_refunded, 2);
            $amount = filled($data['amount'] ?? null) ? round((float) $data['amount'], 2) : $refundable;
            if ($refundable <= 0.005) {
                throw ValidationException::withMessages(['amount' => 'Nothing from this return is held as advance, so there is nothing left to refund.']);
            }
            if ($amount <= 0 || $amount > $refundable + 0.005) {
                throw ValidationException::withMessages(['amount' => "Enter an amount up to ₹{$refundable}."]);
            }

            $customer = $return->customer_id ? Customer::forCompany($return->company_id)->lockForUpdate()->find($return->customer_id) : null;
            if (! $customer) {
                throw ValidationException::withMessages(['amount' => 'This return has no customer.']);
            }
            if ((float) $customer->advance_balance + 0.005 < $amount) {
                $have = round((float) $customer->advance_balance, 2);
                throw ValidationException::withMessages(['amount' => "The customer's advance is only ₹{$have} now (part of it was already used on a bill)."]);
            }

            $customer->advance_balance = round((float) $customer->advance_balance - $amount, 2);
            $customer->save();
            $return->update(['advance_refunded' => round((float) $return->advance_refunded + $amount, 2)]);

            return $this->makeCustomerRefund($return, $customer->id, $amount, $data['mode'], $data['refund_date'] ?? now()->toDateString(), $data['reference'] ?? null, $data['notes'] ?? null, true, $userId);
        });
    }

    private function makeCustomerRefund(SalesReturn $return, ?string $customerId, float $amount, string $mode, string $date, ?string $reference, ?string $notes, bool $fromAdvance, ?int $userId): CustomerRefund
    {
        $refund = CustomerRefund::create([
            'company_id'      => $return->company_id,
            'customer_id'     => $customerId,
            'sales_return_id' => $return->id,
            'refund_no'       => DocumentNumber::next(CustomerRefund::query()->where('company_id', $return->company_id), 'refund_no', 'RFC-', 6),
            'refund_date'     => $date,
            'amount'          => $amount,
            'mode'            => $mode,
            'reference'       => $reference,
            'notes'           => $notes,
            'from_advance'    => $fromAdvance,
            'created_by'      => $userId,
        ]);

        // Money left the shop: Dr Sales (returns) / Cr Cash or Bank.
        $isCash = $mode === 'cash';
        app(AccountingEngine::class)->postFromSource(
            $return->company_id, 'customer_refund', $refund->id,
            $isCash ? 'cash_payment' : 'bank_payment', $date, $amount,
            'sales', $isCash ? 'cash' : 'bank',
            "Refund {$refund->refund_no} for return {$return->return_no}",
        );
        $this->activity->log($return->company_id, $userId, 'refund', 'sales_returns', 'sales_return', $return->id,
            "Refunded ₹{$amount} ({$mode}) for return {$return->return_no}", ['refund_no' => $refund->refund_no]);

        return $refund;
    }

    // ---- purchase return (the supplier pays us) ----------------------------------------------

    /** Called while a purchase return is confirmed: $excess is the credit beyond what we owed the supplier. */
    public function settlePurchaseReturn(PurchaseReturn $return, Supplier $supplier, float $excess, ?int $userId = null): void
    {
        $excess = round($excess, 2);
        if ($excess <= 0.005) {
            return;
        }

        $mode = $return->refund_mode ?: 'advance';
        if ($mode === 'advance') {
            $supplier->advance_balance = round((float) $supplier->advance_balance + $excess, 2);
            $return->update(['advance_credit' => $excess]);

            return;
        }

        $this->makeSupplierRefund($return, $supplier->id, $excess, in_array($mode, self::MODES, true) ? $mode : 'cash', now()->toDateString(), null, 'Received with return '.$return->return_no, false, $userId);
    }

    /**
     * Mark credit held as supplier advance as really received back from the supplier.
     *
     * @param  array{amount?: float|string|null, mode: string, refund_date?: ?string, reference?: ?string, notes?: ?string}  $data
     */
    public function refundPurchaseReturn(PurchaseReturn $return, array $data, ?int $userId = null): SupplierRefund
    {
        return DB::transaction(function () use ($return, $data, $userId) {
            $return = PurchaseReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== 'confirmed') {
                throw ValidationException::withMessages(['status' => 'Only a confirmed return can be refunded.']);
            }

            $refundable = round((float) $return->advance_credit - (float) $return->advance_refunded, 2);
            $amount = filled($data['amount'] ?? null) ? round((float) $data['amount'], 2) : $refundable;
            if ($refundable <= 0.005) {
                throw ValidationException::withMessages(['amount' => 'Nothing from this return is held as supplier advance, so there is nothing left to receive.']);
            }
            if ($amount <= 0 || $amount > $refundable + 0.005) {
                throw ValidationException::withMessages(['amount' => "Enter an amount up to ₹{$refundable}."]);
            }

            $supplier = $return->supplier_id ? Supplier::query()->where('company_id', $return->company_id)->lockForUpdate()->find($return->supplier_id) : null;
            if (! $supplier) {
                throw ValidationException::withMessages(['amount' => 'This return has no supplier.']);
            }
            if ((float) $supplier->advance_balance + 0.005 < $amount) {
                $have = round((float) $supplier->advance_balance, 2);
                throw ValidationException::withMessages(['amount' => "The supplier advance is only ₹{$have} now (part of it was already used on a bill)."]);
            }

            $supplier->advance_balance = round((float) $supplier->advance_balance - $amount, 2);
            $supplier->save();
            $return->update(['advance_refunded' => round((float) $return->advance_refunded + $amount, 2)]);

            return $this->makeSupplierRefund($return, $supplier->id, $amount, $data['mode'], $data['refund_date'] ?? now()->toDateString(), $data['reference'] ?? null, $data['notes'] ?? null, true, $userId);
        });
    }

    private function makeSupplierRefund(PurchaseReturn $return, ?string $supplierId, float $amount, string $mode, string $date, ?string $reference, ?string $notes, bool $fromAdvance, ?int $userId): SupplierRefund
    {
        $refund = SupplierRefund::create([
            'company_id'         => $return->company_id,
            'supplier_id'        => $supplierId,
            'purchase_return_id' => $return->id,
            'refund_no'          => DocumentNumber::next(SupplierRefund::query()->where('company_id', $return->company_id), 'refund_no', 'RFS-', 6),
            'refund_date'        => $date,
            'amount'             => $amount,
            'mode'               => $mode,
            'reference'          => $reference,
            'notes'              => $notes,
            'from_advance'       => $fromAdvance,
            'created_by'         => $userId,
        ]);

        // Money came into the shop: Dr Cash or Bank / Cr Purchases (returns).
        $isCash = $mode === 'cash';
        app(AccountingEngine::class)->postFromSource(
            $return->company_id, 'supplier_refund', $refund->id,
            $isCash ? 'cash_receipt' : 'bank_receipt', $date, $amount,
            $isCash ? 'cash' : 'bank', 'purchases',
            "Refund {$refund->refund_no} for return {$return->return_no}",
        );
        $this->activity->log($return->company_id, $userId, 'refund', 'purchase_returns', 'purchase_return', $return->id,
            "Received ₹{$amount} ({$mode}) for return {$return->return_no}", ['refund_no' => $refund->refund_no]);

        return $refund;
    }
}
