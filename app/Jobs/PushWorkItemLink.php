<?php

namespace App\Jobs;

use App\Enums\IntegrationConnectionStatus;
use App\Models\BaselineItem;
use App\Models\WorkItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;

/**
 * The outbound half of the two-way sync (FA-7, FA-8): when a synced work
 * item is mapped to a deliverable, annotate the provider's issue with a
 * comment so the execution tool shows what the work counts against. Skips
 * quietly if the connection went away in the meantime — the mapping itself
 * is already recorded.
 */
class PushWorkItemLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public WorkItem $workItem,
        public BaselineItem $deliverable,
    ) {
        $this->onQueue('integrations');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        Context::add('organization_id', $this->workItem->organization_id);

        $connection = $this->workItem->integration;
        $issueId = $this->workItem->external_key ?? $this->workItem->external_id;

        if ($connection?->status !== IntegrationConnectionStatus::Connected || $issueId === null) {
            return;
        }

        $connection->client()->postIssueComment(
            $issueId,
            __('Baseline: mapped to deliverable ":title".', ['title' => $this->deliverable->title]),
        );
    }
}
