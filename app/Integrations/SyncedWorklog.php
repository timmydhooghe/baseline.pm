<?php

namespace App\Integrations;

use Carbon\CarbonImmutable;

/**
 * A normalized worklog fetched from a provider, keyed by its external id so
 * repeated syncs upsert instead of duplicating.
 */
final readonly class SyncedWorklog
{
    public function __construct(
        public string $externalId,
        public string $authorName,
        public int $seconds,
        public CarbonImmutable $loggedOn,
    ) {}
}
