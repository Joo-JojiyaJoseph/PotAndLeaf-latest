<?php

namespace App\Enums;

enum PaymentMode: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Upi = 'upi';
    case Credit = 'credit';
}
