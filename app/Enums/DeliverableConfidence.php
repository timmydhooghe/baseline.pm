<?php

namespace App\Enums;

/**
 * The delivery team's confidence that a deliverable lands on its forecast
 * (FA-22). Internal-only — it never reaches a customer-facing snapshot.
 */
enum DeliverableConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }
}
