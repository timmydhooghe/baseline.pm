<?php

namespace App\Enums;

enum CommercialModel: string
{
    case FixedPrice = 'fixed_price';
    case TimeAndMaterials = 'time_and_materials';

    public function label(): string
    {
        return match ($this) {
            self::FixedPrice => 'Fixed price',
            self::TimeAndMaterials => 'Time & materials',
        };
    }
}
