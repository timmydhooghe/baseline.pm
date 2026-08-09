<?php

namespace App\Enums;

/**
 * Who owes a dependency (FA-20). The party decides two things: whether the
 * item appears in the customer's portal action list, and who a resulting
 * delay is attributed to when a milestone slips day for day.
 */
enum DependencyParty: string
{
    case Customer = 'customer';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Internal => 'Internal',
        };
    }

    public function isCustomer(): bool
    {
        return $this === self::Customer;
    }
}
