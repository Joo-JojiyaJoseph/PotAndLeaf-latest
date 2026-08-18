<?php

namespace App\Services;

use App\Actions\StockVerifications\ApproveStockVerification;
use App\Actions\StockVerifications\CreateStockVerification;
use App\Actions\StockVerifications\RejectStockVerification;
use App\Actions\StockVerifications\SubmitStockVerification;
use App\Models\StockVerification;
use App\Repositories\Contracts\StockVerificationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StockVerificationService
{
    public function __construct(
        private readonly StockVerificationRepositoryInterface $verifications,
        private readonly CreateStockVerification $create,
        private readonly SubmitStockVerification $submit,
        private readonly ApproveStockVerification $approve,
        private readonly RejectStockVerification $reject,
    ) {}

    /** @param array<string,mixed> $filters */
    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return $this->verifications->paginateForCompany($companyId, $filters);
    }

    public function find(int|string $companyId, string $id): ?StockVerification
    {
        return $this->verifications->findForCompany($companyId, $id);
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data, ?int $userId = null): StockVerification
    {
        return $this->create->handle($companyId, $data, $userId);
    }

    public function submit(StockVerification $v, ?int $userId = null): StockVerification
    {
        return $this->submit->handle($v, $userId);
    }

    public function approve(StockVerification $v, ?int $userId = null): StockVerification
    {
        return $this->approve->handle($v, $userId);
    }

    public function reject(StockVerification $v, string $reason, ?int $userId = null): StockVerification
    {
        return $this->reject->handle($v, $reason, $userId);
    }
}
