<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PDO;
use Throwable;

/**
 * Exports the live MySQL database into a portable SQLite file (and optionally
 * restores from one). Manual and scheduled paths share this routine.
 */
class SqliteBackupService
{
    public function disk(): string
    {
        return 'local';
    }

    public function directory(): string
    {
        return 'backups';
    }

    public function retain(): int
    {
        return (int) config('backup.retain', 30);
    }

    /** @return array{filename: string, path: string, size: int, type: string, created_at: string} */
    public function run(string $type = 'manual'): array
    {
        $type = in_array($type, ['manual', 'automatic', 'pre-restore'], true) ? $type : 'manual';
        $stamp = now()->format('Ymd_His');
        $filename = "backup_{$stamp}_{$type}.sqlite";
        $relative = $this->directory().'/'.$filename;
        $absolute = Storage::disk($this->disk())->path($relative);

        Storage::disk($this->disk())->makeDirectory($this->directory());

        if (file_exists($absolute)) {
            @unlink($absolute);
        }

        $pdo = new PDO('sqlite:'.$absolute);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL;');

        $tables = $this->listTables();
        foreach ($tables as $table) {
            $this->copyTable($pdo, $table);
        }

        $pdo = null; // release lock

        if (config('filesystems.disks.s3.bucket')) {
            try {
                Storage::disk('s3')->put(
                    $this->directory().'/'.$filename,
                    fopen($absolute, 'r'),
                );
            } catch (Throwable $e) {
                Log::warning('Backup S3 upload failed', ['error' => $e->getMessage()]);
            }
        }

        $this->prune();

        return [
            'filename'   => $filename,
            'path'       => $relative,
            'size'       => (int) (filesize($absolute) ?: 0),
            'type'       => $type,
            'created_at' => now()->toDateTimeString(),
        ];
    }

    /** @return array<int, array{filename: string, size: int, type: string, created_at: string}> */
    public function list(): array
    {
        $files = Storage::disk($this->disk())->files($this->directory());

        return collect($files)
            ->filter(fn ($f) => str_ends_with($f, '.sqlite'))
            ->map(function ($f) {
                $name = basename($f);
                $type = 'manual';
                if (str_contains($name, '_automatic')) {
                    $type = 'automatic';
                } elseif (str_contains($name, '_pre-restore')) {
                    $type = 'pre-restore';
                }

                return [
                    'filename'   => $name,
                    'size'       => (int) Storage::disk($this->disk())->size($f),
                    'type'       => $type,
                    'created_at' => date('Y-m-d H:i:s', Storage::disk($this->disk())->lastModified($f)),
                ];
            })
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    public function absolutePath(string $filename): string
    {
        $safe = basename($filename);
        $path = Storage::disk($this->disk())->path($this->directory().'/'.$safe);
        abort_unless(is_file($path), 404, 'Backup not found.');

        return $path;
    }

    /**
     * Restore SQLite backup into the live MySQL connection.
     * Takes a pre-restore safety backup first.
     */
    public function restore(string $filename): array
    {
        $safety = $this->run('pre-restore');
        $sqlitePath = $this->absolutePath($filename);

        $sqlite = new PDO('sqlite:'.$sqlitePath);
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);

        DB::connection()->disableQueryLog();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                if (! $this->mysqlHasTable($table)) {
                    continue;
                }
                DB::table($table)->truncate();
                $rows = $sqlite->query("SELECT * FROM \"{$table}\"")->fetchAll(PDO::FETCH_ASSOC);
                foreach (array_chunk($rows, 200) as $chunk) {
                    if ($chunk === []) {
                        continue;
                    }
                    DB::table($table)->insert($chunk);
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return ['restored' => $filename, 'safety_backup' => $safety['filename']];
    }

    private function prune(): void
    {
        $retain = $this->retain();
        $files = collect($this->list())->values();
        if ($files->count() <= $retain) {
            return;
        }
        $files->slice($retain)->each(function ($f) {
            Storage::disk($this->disk())->delete($this->directory().'/'.$f['filename']);
        });
    }

    /** @return array<int, string> */
    private function listTables(): array
    {
        $db = DB::getDatabaseName();
        $rows = DB::select('SHOW TABLES');
        $key = 'Tables_in_'.$db;

        return collect($rows)
            ->map(fn ($r) => $r->$key ?? array_values((array) $r)[0])
            ->reject(fn ($t) => in_array($t, ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions'], true))
            ->values()
            ->all();
    }

    private function mysqlHasTable(string $table): bool
    {
        return in_array($table, $this->listTables(), true);
    }

    private function copyTable(PDO $pdo, string $table): void
    {
        $create = DB::selectOne("SHOW CREATE TABLE `{$table}`");
        $createSql = $create->{'Create Table'} ?? null;
        if (! $createSql) {
            return;
        }

        // Convert a subset of MySQL DDL to SQLite-friendly CREATE.
        $sqliteCreate = $this->mysqlCreateToSqlite($createSql, $table);
        $pdo->exec("DROP TABLE IF EXISTS \"{$table}\"");
        $pdo->exec($sqliteCreate);

        $rows = DB::table($table)->get();
        if ($rows->isEmpty()) {
            return;
        }

        $columns = array_keys((array) $rows->first());
        $colList = implode(', ', array_map(fn ($c) => "\"{$c}\"", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare("INSERT INTO \"{$table}\" ({$colList}) VALUES ({$placeholders})");

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $c) {
                $v = $row->$c;
                if (is_bool($v)) {
                    $v = $v ? 1 : 0;
                }
                $values[] = $v;
            }
            $stmt->execute($values);
        }
    }

    private function mysqlCreateToSqlite(string $mysqlCreate, string $table): string
    {
        // Fallback: create a simple table from DESCRIBE if conversion is messy.
        $cols = DB::select("DESCRIBE `{$table}`");
        $parts = [];
        $pk = [];
        foreach ($cols as $col) {
            $name = $col->Field;
            $type = strtolower((string) $col->Type);
            $sqliteType = 'TEXT';
            if (str_contains($type, 'int')) {
                $sqliteType = 'INTEGER';
            } elseif (preg_match('/decimal|float|double/', $type)) {
                $sqliteType = 'REAL';
            } elseif (str_contains($type, 'blob')) {
                $sqliteType = 'BLOB';
            }
            $null = strtoupper((string) $col->Null) === 'NO' ? ' NOT NULL' : '';
            $parts[] = "\"{$name}\" {$sqliteType}{$null}";
            if ($col->Key === 'PRI') {
                $pk[] = "\"{$name}\"";
            }
        }
        if ($pk) {
            $parts[] = 'PRIMARY KEY ('.implode(', ', $pk).')';
        }

        return 'CREATE TABLE "'.$table.'" ('.implode(', ', $parts).')';
    }
}
