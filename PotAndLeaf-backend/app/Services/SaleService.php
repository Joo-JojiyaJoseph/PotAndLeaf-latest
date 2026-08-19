<?php

namespace App\Services;

use App\Models\Sale;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Repositories\Contracts\SaleRepositoryInterface;
use App\Services\ActivityLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private readonly SaleRepositoryInterface $sales,
        private readonly CreateSale $createSale,
        private readonly ConfirmSale $confirmSale,
        private readonly CancelSale $cancelSale,
        private readonly SettingsService $settings,
        private readonly ActivityLogService $activity,
    ) {}

    public function list(int|string|null $companyId, array $filters): LengthAwarePaginator
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
        if ($sale->hasCancelRequest()) {
            throw ValidationException::withMessages(['status' => 'Use approve cancellation to complete this request.']);
        }

        if ($sale->isConfirmed() && $this->settings->get($sale->company_id, 'sale_cancel_requires_approval') === '1') {
            throw ValidationException::withMessages([
                'status' => 'Confirmed sales require HO approval. Submit a cancellation request instead.',
            ]);
        }

        return $this->cancelSale->handle($sale, $userId);
    }

    public function requestCancellation(Sale $sale, string $reason, ?int $userId = null): Sale
    {
        if (! $sale->isConfirmed()) {
            throw ValidationException::withMessages(['status' => 'Only confirmed sales can request cancellation.']);
        }

        if ($sale->hasCancelRequest()) {
            throw ValidationException::withMessages(['status' => 'A cancellation request is already pending.']);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Cancellation reason is required.']);
        }

        $sale->update([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $userId,
            'cancel_reason'       => $reason,
            'cancel_rejection_reason' => null,
            'cancel_reviewed_at'  => null,
            'cancel_reviewed_by'  => null,
        ]);

        $this->activity->log(
            $sale->company_id, $userId, 'cancel_request', 'sales', 'sale', $sale->id,
            "Cancellation requested for {$sale->sale_no}",
            ['reason' => $reason],
        );

        return $sale->refresh()->load(['cancelRequestedBy:id,name']);
    }

    public function approveCancellation(Sale $sale, ?int $userId = null): Sale
    {
        if (! $sale->hasCancelRequest()) {
            throw ValidationException::withMessages(['status' => 'No pending cancellation request for this sale.']);
        }

        $reason = $sale->cancel_reason;
        $requesterId = $sale->cancel_requested_by;

        $cancelled = $this->cancelSale->handle($sale, $userId);

        $this->activity->log(
            $sale->company_id, $userId, 'cancel_approve', 'sales', 'sale', $sale->id,
            "Cancellation approved for {$sale->sale_no}",
            ['reason' => $reason, 'requested_by' => $requesterId],
        );

        return $cancelled;
    }

    public function rejectCancellation(Sale $sale, ?string $reason, ?int $userId = null): Sale
    {
        if (! $sale->hasCancelRequest()) {
            throw ValidationException::withMessages(['status' => 'No pending cancellation request for this sale.']);
        }

        $sale->update([
            'cancel_requested_at'     => null,
            'cancel_requested_by'     => null,
            'cancel_reason'           => null,
            'cancel_reviewed_at'      => now(),
            'cancel_reviewed_by'      => $userId,
            'cancel_rejection_reason' => trim((string) $reason) ?: null,
        ]);

        $this->activity->log(
            $sale->company_id, $userId, 'cancel_reject', 'sales', 'sale', $sale->id,
            "Cancellation rejected for {$sale->sale_no}",
            ['rejection_reason' => $reason],
        );

        return $sale->refresh()->load(['cancelReviewedBy:id,name', 'cancelRequestedBy:id,name']);
    }

    public function convertProforma(Sale $sale, ?int $userId = null): Sale
    {
        if (! $sale->isProforma()) {
            throw ValidationException::withMessages(['status' => 'Only proforma invoices can be converted.']);
        }

        $sale->update([
            'bill_kind'    => 'tax_invoice',
            'status'       => 'draft',
            'confirmed_at' => null,
        ]);

        $this->activity->log(
            $sale->company_id, $userId, 'convert_proforma', 'sales', 'sale', $sale->id,
            "Proforma {$sale->sale_no} converted to tax invoice draft",
        );

        return $sale->refresh()->load(['items', 'customer:id,name,type']);
    }
}
