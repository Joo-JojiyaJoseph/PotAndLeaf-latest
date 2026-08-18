<?php

namespace App\Support\Api;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Resolves which company scopes a list/read query. Super-admins may pass
 * ?company_id=all or ?company_id={id} on GET requests.
 * Mutations always use the X-Company-Id header company via company().
 */
trait ResolvesFilterCompany
{
    /** Header company — use for creates, updates, deletes. */
    protected function company(Request $request): Company
    {
        return $request->attributes->get('company');
    }

    /**
     * Company ID for list/read aggregations.
     * null = all accessible companies (super-admin only).
     */
    protected function listCompanyId(Request $request): ?int
    {
        if ($request->attributes->has('list_company_id')) {
            return $request->attributes->get('list_company_id');
        }

        return $this->company($request)->id;
    }

    /** Company model for form-data when a single company context is required. */
    protected function listCompany(Request $request): Company
    {
        $id = $this->listCompanyId($request);
        if ($id !== null) {
            return Company::find($id) ?? $this->company($request);
        }

        return $this->company($request);
    }

    /** @deprecated alias for listCompany() */
    protected function filterCompany(Request $request): Company
    {
        return $this->listCompany($request);
    }

    /** Apply list/read company filter to a query. null = all companies (super-admin). */
    protected function applyListCompanyScope($query, Request $request, ?string $column = null)
    {
        $id = $this->listCompanyId($request);
        if ($id === null) {
            return $query;
        }

        $column ??= $query->getModel()->getTable().'.company_id';

        return $query->where($column, $id);
    }
}
