<?php

namespace App\Services;

use App\Actions\PurchaseReturns\CancelPurchaseReturn;
use App\Actions\PurchaseReturns\ConfirmPurchaseReturn;
use App\Actions\PurchaseReturns\CreatePurchaseReturn;
use App\Models\PurchaseReturn;
use App\Repositories\Contracts\PurchaseReturnRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PurchaseReturnService
{
    public function __construct(
        private readonly PurchaseReturnRepositoryInterface $returns,
        private readonly CreatePurchaseReturn $createReturn,
        private readonly ConfirmPurchaseReturn $confirmReturn,
        private readonly CancelPurchaseReturn $cancelReturn,
    ) {}

    /** @param array<string,mixed> $filters */
    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return $this->returns->paginateForCompany($companyId, $filters);
    }

    public function find(int|string $companyId, string $id): ?PurchaseReturn
    {
        return $this->returns->findForCompany($companyId, $id);
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data, ?int $userId = null): PurchaseReturn
    {
        return $this->createReturn->handle($companyId, $data, $userId);
    }

    public function confirm(PurchaseReturn $return, ?int $userId = null): PurchaseReturn
    {
        return $this->confirmReturn->handle($return, $userId);
    }

    public function cancel(PurchaseReturn $return, ?int $userId = null): PurchaseReturn
    {
        return $this->cancelReturn->handle($return, $userId);
    }
}
