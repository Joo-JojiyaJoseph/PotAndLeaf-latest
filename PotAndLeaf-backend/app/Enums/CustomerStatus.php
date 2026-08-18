<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
