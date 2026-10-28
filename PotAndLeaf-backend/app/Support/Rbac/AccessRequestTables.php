<?php

namespace App\Support\Rbac;

use Illuminate\Support\Facades\Schema;

/**
 * Are the "Request access" tables there yet (migration 2026_10_28_000001)?
 * Checked once per request so a server that has not run the migration
 * keeps working, and the Request access screen can say what is missing.
 */
class AccessRequestTables
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null || app()->runningUnitTests()) {
            try {
                self::$ready = Schema::hasTable('user_permissions') && Schema::hasTable('access_requests');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    public const MESSAGE = 'Request access is not set up on the server yet. Ask the person who looks after the server to run "php artisan migrate".';
}
