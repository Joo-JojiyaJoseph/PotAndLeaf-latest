<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\WhatsApp\TemplateRenderer;
use Illuminate\Support\Carbon;

class CommissionNotificationService
{
    public function __construct(
        private readonly CommissionEngine $engine,
        private readonly WhatsAppService $whatsapp,
        private readonly SettingsService $settings,
    ) {}

    /** Send EOD commission summaries for all staff with sales or commission on a date. */
    public function sendEodSummaries(int|string $companyId, ?string $date = null, bool $force = false): array
    {
        if ($this->settings->get($companyId, 'whatsapp_enabled') !== '1') {
            return ['sent' => 0, 'skipped' => 0, 'errors' => ['WhatsApp disabled']];
        }

        $date ??= now()->toDateString();
        $company = Company::find($companyId);
        $sent = 0;
        $skipped = 0;
        $errors = [];

        $userIds = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
            ->pluck('id');

        foreach ($userIds as $userId) {
            $summary = $this->engine->dailySummary($companyId, $userId, $date);
            if ($summary['sales_total'] <= 0 && $summary['total_incentive'] <= 0) {
                $skipped++;
                continue;
            }

            if (! $force && WhatsAppMessageLog::forCompany($companyId)
                ->where('message_type', 'eod_commission')
                ->where('recipient_type', 'employee')
                ->where('recipient_id', (string) $userId)
                ->whereDate('business_date', $date)
                ->where('status', 'sent')
                ->exists()) {
                $skipped++;
                continue;
            }

            $user = User::find($userId);
            $phone = $user?->phone;
            if (! $phone) {
                $skipped++;
                continue;
            }

            $message = $this->buildEodMessage($company?->name ?? 'Pot & Leaf', $user->name, $summary, $companyId);
            $log = WhatsAppMessageLog::create([
                'company_id'      => $companyId,
                'recipient_type'  => 'employee',
                'recipient_id'    => (string) $userId,
                'recipient_phone' => $phone,
                'message_type'    => 'eod_commission',
                'message'         => $message,
                'status'          => 'pending',
                'business_date'   => $date,
            ]);

            $result = $this->whatsapp->sendMessage($phone, $message);
            if ($result['success']) {
                $log->update(['status' => 'sent', 'sent_at' => now()]);
                $sent++;
            } else {
                $log->update(['status' => 'failed', 'error' => $result['message'] ?? 'Send failed']);
                $errors[] = "{$user->name}: {$result['message']}";
            }
        }

        return compact('sent', 'skipped', 'errors');
    }

    public function buildEodMessage(string $companyName, string $employeeName, array $summary, int|string|null $companyId = null): string
    {
        $money = fn ($n) => '₹'.number_format((float) $n, 2);
        $pct = number_format((float) ($summary['target_achievement_pct'] ?? 0), 1);

        $vars = [
            'company_name'           => $companyName,
            'employee_name'          => $employeeName,
            'date'                   => Carbon::parse($summary['date'])->format('d M Y'),
            'sales_total'            => $money($summary['sales_total']),
            'daily_target'           => $summary['daily_target'] > 0 ? $money($summary['daily_target']) : '—',
            'target_achievement_pct' => $pct,
            'sales_commission'       => $money($summary['sales_commission']),
            'daily_target_bonus'     => $money($summary['daily_target_bonus']),
            'promotion_bonus'        => $money($summary['promotion_bonus']),
            'supervisor_commission'  => $money($summary['supervisor_commission']),
            'manager_commission'     => $money($summary['manager_commission']),
            'total_incentive'        => $money($summary['total_incentive']),
        ];

        if ($companyId) {
            $tpl = WhatsAppTemplate::forCompany($companyId)->where('slug', 'eod_commission')->where('is_active', true)->first();
            if ($tpl) {
                return TemplateRenderer::render($tpl->body, $vars);
            }
        }

        return implode("\n", array_filter([
            '*EOD Commission & Incentive Summary*',
            $companyName,
            "Employee: {$employeeName}",
            'Date: '.Carbon::parse($summary['date'])->format('d M Y'),
            '',
            'Sales: '.$money($summary['sales_total']),
            $summary['daily_target'] > 0 ? 'Target: '.$money($summary['daily_target']) : null,
            $summary['daily_target'] > 0 ? "Target Achievement: {$pct}%" : null,
            '',
            'Sales Commission: '.$money($summary['sales_commission']),
            'Target Bonus: '.$money($summary['daily_target_bonus']),
            'Promotion Bonus: '.$money($summary['promotion_bonus']),
            $summary['supervisor_commission'] > 0 ? 'Supervisor Commission: '.$money($summary['supervisor_commission']) : null,
            $summary['manager_commission'] > 0 ? 'Manager Commission: '.$money($summary['manager_commission']) : null,
            '',
            '*Total Incentive: '.$money($summary['total_incentive']).'*',
        ]));
    }
}
