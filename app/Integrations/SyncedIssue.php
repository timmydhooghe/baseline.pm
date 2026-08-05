<?php

namespace App\Integrations;

use App\Enums\EstimateUnit;
use App\Enums\WorkItemState;
use Carbon\CarbonImmutable;

/**
 * A normalized issue fetched from a provider. Provider-specific workflow
 * names travel in externalStatus for display; state is the normalized
 * workflow position everything else reasons about. Estimates keep their
 * native unit (FA-7).
 */
final readonly class SyncedIssue
{
    /**
     * @param  list<SyncedWorklog>  $worklogs
     */
    public function __construct(
        public string $externalId,
        public ?string $externalKey,
        public string $title,
        public ?string $externalStatus,
        public WorkItemState $state,
        public ?string $type,
        public ?string $assigneeName,
        public ?string $url,
        public ?float $estimateValue,
        public ?EstimateUnit $estimateUnit,
        public ?CarbonImmutable $externalUpdatedAt,
        public array $worklogs = [],
    ) {}
}
