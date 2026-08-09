<?php

namespace App\Enums;

/**
 * The life of a risk register entry (FA-19). Open risks are watched,
 * mitigating ones have a plan in flight, and both stay on the register.
 * Closed means it can no longer happen; materialised means it did — either
 * way the entry and its rating history remain on record.
 */
enum RiskStatus: string
{
    case Open = 'open';
    case Mitigating = 'mitigating';
    case Closed = 'closed';
    case Materialised = 'materialised';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Mitigating => 'Mitigating',
            self::Closed => 'Closed',
            self::Materialised => 'Materialised',
        };
    }

    /**
     * Whether the risk still threatens the engagement — only live entries
     * carry exposure into the margin risk band or surface on Today.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Open, self::Mitigating], true);
    }
}
