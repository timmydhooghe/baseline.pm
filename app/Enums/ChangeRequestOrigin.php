<?php

namespace App\Enums;

/**
 * Where a change request came from (FA-12): a scope creep item promoted out
 * of the triage inbox, or a request raised by hand from a steering call, an
 * email or another channel.
 */
enum ChangeRequestOrigin: string
{
    case ScopeCreep = 'scope_creep';
    case SteeringCall = 'steering_call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ScopeCreep => 'Scope creep item',
            self::SteeringCall => 'Steering call',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::Other => 'Other',
        };
    }
}
