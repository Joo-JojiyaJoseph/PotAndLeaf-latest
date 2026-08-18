<?php

namespace App\Services;

use App\Actions\BulkSplits\CancelBulkSplit;
use App\Actions\BulkSplits\ConfirmBulkSplit;
use App\Actions\BulkSplits\CreateBulkSplit;
use App\Models\BulkSplit;
use App\Repositories\Contracts\BulkSplitRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BulkSplitService
{
    public function __construct(
        private readonly BulkSplitRepositoryInterface $splits,
        private readonly CreateBulkSplit $createSplit,
        private readonly ConfirmBulkSplit $confirmSplit,
        private readonly CancelBulkSplit $cancelSplit,
    ) {}

    public function list(int|string $companyId, array $filters): LengthAwarePaginator
    {
        return $this->splits->paginateForCompany($companyId, $filters);
    }

    public function find(int|string $companyId, string $id): ?BulkSplit
    {
        return $this->splits->findForCompany($companyId, $id);
    }

    public function create(int|string $companyId, array $data, ?int $userId = null): BulkSplit
    {
        return $this->createSplit->handle($companyId, $data, $userId);
    }

    public function confirm(BulkSplit $split, ?int $userId = null): BulkSplit
    {
        return $this->confirmSplit->handle($split, $userId);
    }

    public function cancel(BulkSplit $split, ?int $userId = null): BulkSplit
    {
        return $this->cancelSplit->handle($split, $userId);
    }
}
