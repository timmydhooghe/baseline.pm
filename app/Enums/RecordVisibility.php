<?php

namespace App\Enums;

/**
 * The visibility model on shareable records (FA-22, FA-27): internal stays
 * inside the organization, shared may appear in customer-facing snapshots
 * and the portal. Cost, rates and margin are never shareable at all.
 */
enum RecordVisibility: string
{
    case Internal = 'internal';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::Shared => 'Shared',
        };
    }

    public function isShared(): bool
    {
        return $this === self::Shared;
    }
}
