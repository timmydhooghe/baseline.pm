<?php

namespace App\Jobs;

use App\Enums\EngagementStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * One inbound sync pass (FA-7): pull issues, worklogs and releases from the
 * connection's provider and upsert them into the engagement's work history.
 * Every pass leaves a SyncRun — succeeded with counts, or failed with the
 * error — so the sync status is always visible. Retries are Horizon's:
 * provider APIs flake, so the run reports honestly and the job rethrows.
 */
class SyncIntegrationConnection implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /*
     * Named $integration because the Queueable trait already owns
     * $connection (the queue connection name).
     */
    public function __construct(public IntegrationConnection $integration)
    {
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
        Context::add('organization_id', $this->integration->organization_id);

        if ($this->integration->status !== IntegrationConnectionStatus::Connected) {
            return;
        }

        // Archived engagements are read-only — a job queued before the
        // archival must not keep writing work into them.
        if ($this->integration->engagement->status === EngagementStatus::Archived) {
            return;
        }

        $run = $this->integration->startSyncRun();

        try {
            $client = $this->integration->client();
            $counts = $this->integration->applySyncedIssues($client->fetchIssues());
            $counts['releases'] = $this->integration->applySyncedReleases($client->fetchReleases());

            $this->integration->last_synced_at = now();
            $this->integration->save();

            $run->markSucceeded($counts);
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }
    }
}
