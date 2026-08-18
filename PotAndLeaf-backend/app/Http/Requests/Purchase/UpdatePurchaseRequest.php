<?php

namespace App\Http\Requests\Purchase;

class UpdatePurchaseRequest extends StorePurchaseRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('purchases.update', $this->teamId());
    }
}
