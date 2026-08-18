<?php

namespace App\Enums;

enum CustomerType: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
    case Dealer = 'dealer';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
