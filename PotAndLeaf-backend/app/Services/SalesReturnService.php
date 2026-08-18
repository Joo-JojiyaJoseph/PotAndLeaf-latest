<?php

namespace App\Services;

use App\Actions\SalesReturns\CancelSalesReturn;
use App\Actions\SalesReturns\ConfirmSalesReturn;
use App\Actions\SalesReturns\CreateSalesReturn;
use App\Models\SalesReturn;
use App\Repositories\Contracts\SalesReturnRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SalesReturnService
{
    public function __construct(
        private readonly SalesReturnRepositoryInterface $returns,
        private readonly CreateSalesReturn $createReturn,
        private readonly ConfirmSalesReturn $confirmReturn,
        private readonly CancelSalesReturn $cancelReturn,
    ) {}

    /** @param array<string,mixed> $filters */
    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return $this->returns->paginateForCompany($companyId, $filters);
    }

    public function find(int|string $companyId, string $id): ?SalesReturn
    {
        return $this->returns->findForCompany($companyId, $id);
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data, ?int $userId = null): SalesReturn
    {
        return $this->createReturn->handle($companyId, $data, $userId);
    }

    public function confirm(SalesReturn $return, ?int $userId = null): SalesReturn
    {
        return $this->confirmReturn->handle($return, $userId);
    }

    public function cancel(SalesReturn $return, ?int $userId = null): SalesReturn
    {
        return $this->cancelReturn->handle($return, $userId);
    }
}
