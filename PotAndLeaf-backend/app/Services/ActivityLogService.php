<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Str;

class ActivityLogService
{
    public function log(
        int|string $companyId,
        ?int $userId,
        string $action,
        string $module,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $description = null,
        ?array $meta = null,
    ): ActivityLog {
        return ActivityLog::create([
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'action'      => $action,
            'module'      => $module,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'description' => $description,
            'meta'        => $meta,
        ]);
    }
}
