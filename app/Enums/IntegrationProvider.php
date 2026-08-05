<?php

namespace App\Enums;

/**
 * An execution tool an engagement can sync work from (FA-7). Providers map
 * onto the matching WorkItemSource; standalone engagements have no provider —
 * their work items are recorded manually.
 */
enum IntegrationProvider: string
{
    case Jira = 'jira';
    case Linear = 'linear';

    public function label(): string
    {
        return match ($this) {
            self::Jira => 'Jira',
            self::Linear => 'Linear',
        };
    }

    public function workItemSource(): WorkItemSource
    {
        return match ($this) {
            self::Jira => WorkItemSource::Jira,
            self::Linear => WorkItemSource::Linear,
        };
    }
}
