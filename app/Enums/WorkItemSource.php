<?php

namespace App\Enums;

/**
 * Where a work item originated. Synced items mirror their provider and are
 * updated by sync runs; manual items are the standalone execution mode and
 * stay editable by hand (FA-4, FA-7).
 */
enum WorkItemSource: string
{
    case Jira = 'jira';
    case Linear = 'linear';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Jira => 'Jira',
            self::Linear => 'Linear',
            self::Manual => 'Manual',
        };
    }
}
