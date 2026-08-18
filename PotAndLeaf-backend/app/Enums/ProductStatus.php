<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Discontinued = 'discontinued';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Discontinued => 'Discontinued',
        };
    }

    /** @return array<int, array{value:string, label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
