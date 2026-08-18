<?php

namespace App\Console\Commands;

use App\Services\Backup\SqliteBackupService;
use Illuminate\Console\Command;

class RunDatabaseBackup extends Command
{
    protected $signature = 'backup:run {--type=automatic : automatic|manual}';

    protected $description = 'Export the current database to a portable SQLite backup file';

    public function handle(SqliteBackupService $backups): int
    {
        $type = (string) $this->option('type');
        $result = $backups->run($type);
        $this->info("Backup written: {$result['filename']} ({$result['size']} bytes)");

        return self::SUCCESS;
    }
}
