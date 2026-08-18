<?php

namespace App\Services;

use App\Actions\Purchases\CancelPurchase;
use App\Actions\Purchases\ConfirmPurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Actions\Purchases\UpdatePurchase;
use App\Models\Purchase;
use App\Repositories\Contracts\PurchaseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PurchaseService
{
    public function __construct(
        private readonly PurchaseRepositoryInterface $purchases,
        private readonly CreatePurchase $createPurchase,
        private readonly UpdatePurchase $updatePurchase,
        private readonly ConfirmPurchase $confirmPurchase,
        private readonly CancelPurchase $cancelPurchase,
    ) {}

    /** @param array<string,mixed> $filters */
    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return $this->purchases->paginateForCompany($companyId, $filters);
    }

    public function find(int|string $companyId, string $id): ?Purchase
    {
        return $this->purchases->findForCompany($companyId, $id);
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data, ?int $userId = null): Purchase
    {
        return $this->createPurchase->handle($companyId, $data, $userId);
    }

    /** @param array<string,mixed> $data */
    public function update(Purchase $purchase, array $data, ?int $userId = null): Purchase
    {
        return $this->updatePurchase->handle($purchase, $data, $userId);
    }

    public function confirm(Purchase $purchase, ?int $userId = null): Purchase
    {
        return $this->confirmPurchase->handle($purchase, $userId);
    }

    public function cancel(Purchase $purchase, ?int $userId = null): Purchase
    {
        return $this->cancelPurchase->handle($purchase, $userId);
    }
}
