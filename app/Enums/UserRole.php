<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case DeliveryManager = 'delivery_manager';
    case CommercialManager = 'commercial_manager';
    case Member = 'member';
    case PortfolioViewer = 'portfolio_viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::DeliveryManager => 'Delivery manager',
            self::CommercialManager => 'Commercial manager',
            self::Member => 'Member',
            self::PortfolioViewer => 'Portfolio viewer',
        };
    }

    /**
     * Whether this role manages commercial or delivery data within the organization.
     */
    public function isManager(): bool
    {
        return match ($this) {
            self::Owner, self::DeliveryManager, self::CommercialManager => true,
            self::Member, self::PortfolioViewer => false,
        };
    }

    /**
     * Whether this role publishes rate card versions. Rates are commercial
     * terms, so delivery managers read them but don't set them.
     */
    public function managesRateCard(): bool
    {
        return match ($this) {
            self::Owner, self::CommercialManager => true,
            self::DeliveryManager, self::Member, self::PortfolioViewer => false,
        };
    }
}
