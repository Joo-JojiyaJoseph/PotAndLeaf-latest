<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Backup\SqliteBackupService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SqliteBackupService $backups) {}

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'backup.view');

        return $this->ok(['backups' => $this->backups->list()]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->allow($request, 'backup.run');
        $result = $this->backups->run('manual');

        return $this->created($result, 'Manual backup completed.');
    }

    public function download(Request $request, string $filename): BinaryFileResponse
    {
        $this->allow($request, 'backup.view');
        $path = $this->backups->absolutePath($filename);

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/x-sqlite3',
        ]);
    }

    public function restore(Request $request, string $filename): JsonResponse
    {
        $this->allow($request, 'backup.restore');
        $result = $this->backups->restore($filename);

        return $this->ok($result, 'Database restored. A pre-restore safety backup was created.');
    }

    private function allow(Request $request, string $permission): void
    {
        $company = $request->attributes->get('company');
        $user = $request->user();
        $ok = $user->is_super_admin
            || $user->hasPermission('*', $company->id)
            || $user->hasPermission($permission, $company->id);
        abort_unless($ok, 403);
    }
}
