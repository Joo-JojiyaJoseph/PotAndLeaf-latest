<?php

namespace App\Actions\StockVerifications;

use App\Models\StockVerification;
use Illuminate\Validation\ValidationException;

class SubmitStockVerification
{
    public function handle(StockVerification $verification, ?int $userId = null): StockVerification
    {
        if (! $verification->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft counts can be submitted.']);
        }

        $verification->update(['status' => 'submitted', 'submitted_at' => now()]);

        return $verification->refresh()->load('items');
    }
}
