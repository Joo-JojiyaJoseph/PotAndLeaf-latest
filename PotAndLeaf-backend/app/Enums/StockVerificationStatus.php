<?php

namespace App\Enums;

enum StockVerificationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    /** Stock already changed by the count; waiting to be checked. */
    case Applied = 'applied';
    case Verified = 'verified';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
