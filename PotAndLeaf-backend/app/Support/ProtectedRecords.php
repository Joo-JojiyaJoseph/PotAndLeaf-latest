<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/** Default HO company and admin that must not be deleted. */
class ProtectedRecords
{
    public const HO_COMPANY_CODE = 'CHK-HO';

    public const DEFAULT_ADMIN_EMAIL = 'admin@potandleaf.test';

    public static function isProtectedCompany(Company $company): bool
    {
        return (bool) $company->is_protected || $company->code === self::HO_COMPANY_CODE;
    }

    public static function isProtectedUser(User $user): bool
    {
        return (bool) $user->is_super_admin && strtolower($user->email) === self::DEFAULT_ADMIN_EMAIL;
    }
}
