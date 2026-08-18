<?php

namespace App\Services;

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\Sale;
use App\Repositories\Contracts\SaleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SaleService
{
    public function __construct(
        private readonly SaleRepositoryInterface $sales,
        private readonly CreateSale $createSale,
        private readonly ConfirmSale $confirmSale,
        private readonly CancelSale $cancelSale,
    ) {}

    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return $this->sales->paginateForCompany($companyId, $filters);
    }

    public function find(int|string $companyId, string $id): ?Sale
    {
        return $this->sales->findForCompany($companyId, $id);
    }

    public function create(int|string $companyId, array $data, ?int $userId = null): Sale
    {
        return $this->createSale->handle($companyId, $data, $userId);
    }

    public function confirm(Sale $sale, ?int $userId = null): Sale
    {
        return $this->confirmSale->handle($sale, $userId);
    }

    public function cancel(Sale $sale, ?int $userId = null): Sale
    {
        return $this->cancelSale->handle($sale, $userId);
    }
}
