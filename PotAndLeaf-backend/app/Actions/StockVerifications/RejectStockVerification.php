<?php

namespace App\Actions\StockVerifications;

use App\Models\StockVerification;
use Illuminate\Validation\ValidationException;

class RejectStockVerification
{
    public function handle(StockVerification $verification, string $reason, ?int $userId = null): StockVerification
    {
        if (! $verification->isSubmitted()) {
            throw ValidationException::withMessages(['status' => 'Only submitted counts can be rejected.']);
        }

        $verification->update(['status' => 'rejected', 'rejection_reason' => $reason]);

        return $verification->refresh()->load('items');
    }
}
