<?php

namespace App\Actions\StockVerifications;

use App\Models\StockVerification;
use App\Services\ActivityLogService;
use Illuminate\Validation\ValidationException;

/**
 * Head office / admin has checked a count whose stock was already changed. Nothing moves; the count is
 * marked verified. (If the count turns out wrong, record a new count to correct the stock.)
 */
class VerifyStockVerification
{
    public function __construct(private readonly ActivityLogService $activity) {}

    public function handle(StockVerification $verification, ?int $userId = null, ?string $note = null): StockVerification
    {
        if (! $verification->isApplied()) {
            throw ValidationException::withMessages(['status' => 'Only a count whose stock was updated and is waiting to be checked can be verified.']);
        }

        $verification->update([
            'status'      => 'verified',
            'verified_at' => now(),
            'verified_by' => $userId,
            'notes'       => $note ? trim(($verification->notes ? $verification->notes."\n" : '').'Verified: '.$note) : $verification->notes,
        ]);

        $this->activity->log(
            $verification->company_id, $userId, 'stock_count_verified', 'stock_verifications', 'stock_verification', $verification->id,
            "Stock count {$verification->count_no} verified",
        );

        return $verification->refresh()->load('items');
    }
}
