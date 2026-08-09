<?php

namespace App\Enums;

/**
 * Where a decision record came from (FA-18): recorded by hand, or proposed
 * as a draft from a meeting transcript and confirmed afterwards. The source
 * stays on the record so a reader can tell a deliberate entry from an
 * extracted one.
 */
enum DecisionSource: string
{
    case Manual = 'manual';
    case Transcript = 'transcript';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Recorded by hand',
            self::Transcript => 'Proposed from transcript',
        };
    }
}
