<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Automatically stamps created_by / updated_by / deleted_by from the
 * authenticated user. Add `use HasAuditColumns;` to any model that carries
 * the audit envelope. Requires those three nullable columns on the table.
 */
trait HasAuditColumns
{
    public static function bootHasAuditColumns(): void
    {
        static::creating(function (Model $model): void {
            if (Auth::check()) {
                $model->created_by ??= Auth::id();
                $model->updated_by ??= Auth::id();
            }
        });

        static::updating(function (Model $model): void {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        static::deleting(function (Model $model): void {
            // Only meaningful for soft-deletes; persist quietly without
            // firing another updating cycle.
            if (Auth::check() && method_exists($model, 'trashed')) {
                $model->deleted_by = Auth::id();
                $model->saveQuietly();
            }
        });
    }
}
