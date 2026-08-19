<?php

namespace App\Console\Commands;

use App\Models\WhatsAppMessageLog;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;

class RetryFailedWhatsAppMessages extends Command
{
    protected $signature = 'whatsapp:retry-failed {--limit=50}';

    protected $description = 'Retry failed WhatsApp messages (up to 3 attempts)';

    public function handle(WhatsAppService $whatsapp): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $logs = WhatsAppMessageLog::query()
            ->where('status', 'failed')
            ->where('retry_count', '<', 3)
            ->whereNotNull('recipient_phone')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $sent = 0;
        foreach ($logs as $log) {
            $result = $whatsapp->sendMessage($log->recipient_phone, (string) $log->message);
            $log->increment('retry_count');

            if ($result['success']) {
                $log->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
                $sent++;
            } else {
                $log->update(['error' => $result['message'] ?? 'Retry failed']);
            }
        }

        $this->info("Retried {$logs->count()} message(s); {$sent} sent.");

        return self::SUCCESS;
    }
}
