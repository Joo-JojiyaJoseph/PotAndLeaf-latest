<?php

namespace App\Support\Api;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Resolves which company scopes a list/read query. Super-admins may pass
 * ?company_id= (also applied in ResolveApiCompany middleware as list_company).
 * Mutations always use the X-Company-Id header company via company().
 */
trait ResolvesFilterCompany
{
    protected function filterCompany(Request $request): Company
    {
        if ($request->attributes->has('list_company')) {
            return $request->attributes->get('list_company');
        }

        $current = $request->attributes->get('company');

        if ($request->user()?->is_super_admin && $request->filled('company_id')) {
            $target = Company::find((int) $request->query('company_id'));
            if ($target) {
                return $target;
            }
        }

        return $current;
    }

    /** Header company — use for creates, updates, deletes. */
    protected function company(Request $request): Company
    {
        return $request->attributes->get('company');
    }

    /** Company for index/list/read aggregations — respects ?company_id= filter. */
    protected function listCompany(Request $request): Company
    {
        return $this->filterCompany($request);
    }
}
