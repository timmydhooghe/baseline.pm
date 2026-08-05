<?php

namespace App\Integrations;

use Carbon\CarbonImmutable;

/**
 * A normalized release fetched from a provider: a Jira project version or a
 * Linear release.
 */
final readonly class SyncedRelease
{
    public function __construct(
        public string $externalId,
        public string $name,
        public bool $released,
        public ?CarbonImmutable $releasedOn,
        public ?string $url,
    ) {}
}
