<?php

namespace App\Enums;

/**
 * Where execution work is tracked. The mode can change later without losing
 * governance history (FA-4).
 */
enum ExecutionMode: string
{
    case Standalone = 'standalone';
    case Jira = 'jira';
    case Linear = 'linear';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Standalone => 'Standalone',
            self::Jira => 'Jira',
            self::Linear => 'Linear',
            self::Mixed => 'Mixed',
        };
    }
}
