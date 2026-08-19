<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\EodManagementLog;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/** Business-wide end-of-day management summary (WhatsApp + email). */
class EodManagementSummaryService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly SalesAnalyticsService $analytics,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly SettingsService $settings,
        private readonly WhatsAppService $whatsapp,
    ) {}

    /** Build the full management summary payload for a business date. */
    public function build(int|string $companyId, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $metrics = $this->analytics->salesMetrics($companyId, $date, $date);
        $dashboard = $this->reports->dashboard($companyId, $date, $date, null);
        $reorder = $this->purchaseOrders->reorderReport($companyId);
        $reorderLines = $this->purchaseOrders->reorderSuggestions($companyId);
        $leaderboard = $this->analytics->leaderboard($companyId, 'month', $date, null);

        $branchSales = Sale::forCompany($companyId)
            ->where('status', 'confirmed')
            ->whereNotIn('bill_kind', ['proforma'])
            ->whereDate('sale_date', $date)
            ->selectRaw('location_id, SUM(grand_total) as gross, COUNT(*) as invoices')
            ->groupBy('location_id')
            ->get();

        $locations = Location::forCompany($companyId)->whereIn('id', $branchSales->pluck('location_id'))->pluck('name', 'id');

        $cashIn = round((float) CustomerReceipt::forCompany($companyId)
            ->where('mode', 'cash')
            ->whereDate('receipt_date', $date)
            ->sum('amount'), 2);

        $bankIn = round((float) CustomerReceipt::forCompany($companyId)
            ->whereIn('mode', ['bank', 'cheque', 'upi', 'card'])
            ->whereDate('receipt_date', $date)
            ->sum('amount'), 2);

        $lowStock = Product::forCompany($companyId)
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->where('reorder_level', '>', 0)
            ->orderBy('name')
            ->limit(10)
            ->get(['sku', 'name', 'current_stock', 'reorder_level']);

        return [
            'date'          => $date,
            'company_id'    => $companyId,
            'sales'         => [
                'gross'           => $metrics['gross_sales'],
                'net'             => $metrics['net_sales'],
                'returns'         => $metrics['returns'],
                'discounts'       => $metrics['discounts'],
                'tax'             => $metrics['tax'],
                'invoices'        => $metrics['invoice_count'],
                'customers'       => $metrics['customer_count'],
                'branch_wise'     => $branchSales->map(fn ($r) => [
                    'branch'   => $locations[$r->location_id] ?? 'Unknown',
                    'gross'    => round((float) $r->gross, 2),
                    'invoices' => (int) $r->invoices,
                ])->values()->all(),
            ],
            'production'    => $dashboard['production'],
            'inventory'     => [
                'stock_value'   => $dashboard['inventory']['stock_value'] ?? 0,
                'low_stock'     => $dashboard['inventory']['low_stock'] ?? 0,
                'reorder_count' => (int) ($reorder['summary']['product_count'] ?? 0),
                'reorder_items' => array_slice($reorderLines, 0, 5),
                'low_stock_items' => $lowStock->map(fn ($p) => [
                    'sku'   => $p->sku,
                    'name'  => $p->name,
                    'stock' => (float) $p->current_stock,
                    'reorder_level' => (float) $p->reorder_level,
                ])->all(),
            ],
            'financial'     => [
                'cash_collection'  => $cashIn,
                'bank_collection'  => $bankIn,
                'receivables'      => round((float) Customer::forCompany($companyId)->sum('outstanding'), 2),
                'payables'         => round((float) Supplier::forCompany($companyId)->sum('outstanding'), 2),
                'ageing_receivables' => $this->reports->ageingReceivables($companyId)['buckets'] ?? [],
            ],
            'top_performers' => array_slice($leaderboard['rankings'] ?? [], 0, 5),
            'transfers'     => $dashboard['transfers'] ?? [],
        ];
    }

    /** Send management EOD via WhatsApp and email; idempotent per recipient per date. */
    public function send(int|string $companyId, ?string $date = null, bool $force = false): array
    {
        if ($this->settings->get($companyId, 'eod_management_enabled') !== '1') {
            return ['whatsapp_sent' => 0, 'email_sent' => 0, 'skipped' => 0, 'errors' => ['EOD management disabled']];
        }

        $date ??= now()->toDateString();
        $company = Company::find($companyId);
        $summary = $this->build($companyId, $date);
        $text = $this->formatWhatsApp($company?->name ?? 'Pot & Leaf', $summary);
        $html = $this->formatEmailHtml($company?->name ?? 'Pot & Leaf', $summary);

        $whatsappSent = 0;
        $emailSent = 0;
        $skipped = 0;
        $errors = [];

        $phones = $this->parseList($this->settings->get($companyId, 'eod_management_whatsapp_phones'));
        if ($this->settings->get($companyId, 'whatsapp_enabled') === '1') {
            foreach ($phones as $phone) {
                if (! $force && $this->alreadySent($companyId, 'whatsapp', $phone, $date)) {
                    $skipped++;
                    continue;
                }

                $log = EodManagementLog::create([
                    'company_id'    => $companyId,
                    'channel'       => 'whatsapp',
                    'recipient'     => $phone,
                    'business_date' => $date,
                    'status'        => 'pending',
                ]);

                $result = $this->whatsapp->sendMessage($phone, $text);
                if ($result['success']) {
                    $log->update(['status' => 'sent', 'sent_at' => now()]);
                    $whatsappSent++;
                } else {
                    $log->update(['status' => 'failed', 'error' => $result['message'] ?? 'Send failed']);
                    $errors[] = "WhatsApp {$phone}: ".($result['message'] ?? 'failed');
                }
            }
        }

        $emails = $this->parseList($this->settings->get($companyId, 'eod_management_email_recipients'));
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (! $force && $this->alreadySent($companyId, 'email', $email, $date)) {
                $skipped++;
                continue;
            }

            $log = EodManagementLog::create([
                'company_id'    => $companyId,
                'channel'       => 'email',
                'recipient'     => $email,
                'business_date' => $date,
                'status'        => 'pending',
            ]);

            try {
                Mail::html($html, function ($message) use ($email, $company, $date) {
                    $message->to($email)
                        ->subject('EOD Summary — '.($company?->name ?? 'Pot & Leaf').' — '.Carbon::parse($date)->format('d M Y'));
                });
                $log->update(['status' => 'sent', 'sent_at' => now()]);
                $emailSent++;
            } catch (\Throwable $e) {
                $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
                $errors[] = "Email {$email}: ".$e->getMessage();
            }
        }

        return [
            'whatsapp_sent' => $whatsappSent,
            'email_sent'    => $emailSent,
            'skipped'       => $skipped,
            'errors'        => $errors,
            'summary'       => $summary,
        ];
    }

    public function formatWhatsApp(string $companyName, array $summary): string
    {
        $m = fn ($n) => '₹'.number_format((float) $n, 2);
        $date = Carbon::parse($summary['date'])->format('d M Y');
        $s = $summary['sales'];
        $lines = [
            '*EOD Management Summary*',
            $companyName,
            "Date: {$date}",
            '',
            '*Sales*',
            "Gross: {$m($s['gross'])} | Net: {$m($s['net'])}",
            "Invoices: {$s['invoices']} | Returns: {$m($s['returns'])}",
            "GST: {$m($s['tax'])} | Discounts: {$m($s['discounts'])}",
        ];

        foreach ($s['branch_wise'] as $b) {
            $lines[] = "· {$b['branch']}: {$m($b['gross'])} ({$b['invoices']} inv)";
        }

        $p = $summary['production'];
        $lines[] = '';
        $lines[] = '*Production*';
        $lines[] = "Completed: {$p['completed']} | Output value: {$m($p['output_value'] ?? 0)}";

        $inv = $summary['inventory'];
        $lines[] = '';
        $lines[] = '*Inventory*';
        $lines[] = "Low stock SKUs: {$inv['low_stock']} | Reorder items: {$inv['reorder_count']}";

        $f = $summary['financial'];
        $lines[] = '';
        $lines[] = '*Financial*';
        $lines[] = "Cash in: {$m($f['cash_collection'])} | Bank in: {$m($f['bank_collection'])}";
        $lines[] = "Receivables: {$m($f['receivables'])} | Payables: {$m($f['payables'])}";

        if (! empty($summary['top_performers'])) {
            $lines[] = '';
            $lines[] = '*Top performers (month)*';
            foreach ($summary['top_performers'] as $r) {
                $lines[] = "#{$r['rank']} {$r['user_name']}: {$m($r['net_sales'])}";
            }
        }

        return implode("\n", $lines);
    }

    public function formatEmailHtml(string $companyName, array $summary): string
    {
        $m = fn ($n) => '₹'.number_format((float) $n, 2);
        $date = Carbon::parse($summary['date'])->format('d M Y');
        $s = $summary['sales'];
        $f = $summary['financial'];
        $p = $summary['production'];
        $inv = $summary['inventory'];

        $performers = collect($summary['top_performers'] ?? [])
            ->map(fn ($r) => "<li>#{$r['rank']} {$r['user_name']} — {$m($r['net_sales'])}</li>")
            ->implode('');

        return <<<HTML
        <h2>EOD Management Summary</h2>
        <p><strong>{$companyName}</strong> · {$date}</p>
        <h3>Sales</h3>
        <ul>
            <li>Gross: {$m($s['gross'])} | Net: {$m($s['net'])}</li>
            <li>Invoices: {$s['invoices']} | Returns: {$m($s['returns'])}</li>
            <li>GST: {$m($s['tax'])} | Discounts: {$m($s['discounts'])}</li>
        </ul>
        <h3>Production</h3>
        <p>Completed runs: {$p['completed']} · Output value: {$m($p['output_value'] ?? 0)}</p>
        <h3>Inventory</h3>
        <p>Low stock: {$inv['low_stock']} SKUs · Reorder items: {$inv['reorder_count']}</p>
        <h3>Financial</h3>
        <ul>
            <li>Cash collection: {$m($f['cash_collection'])}</li>
            <li>Bank collection: {$m($f['bank_collection'])}</li>
            <li>Receivables: {$m($f['receivables'])} | Payables: {$m($f['payables'])}</li>
        </ul>
        <h3>Top performers</h3>
        <ol>{$performers}</ol>
        HTML;
    }

    /** @return list<string> */
    private function parseList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $raw) ?: [])));
    }

    private function alreadySent(int|string $companyId, string $channel, string $recipient, string $date): bool
    {
        return EodManagementLog::query()
            ->where('company_id', $companyId)
            ->where('channel', $channel)
            ->where('recipient', $recipient)
            ->whereDate('business_date', $date)
            ->where('status', 'sent')
            ->exists();
    }
}
