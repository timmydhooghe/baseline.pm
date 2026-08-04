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
}
