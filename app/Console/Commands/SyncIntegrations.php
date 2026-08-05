<?php

namespace App\Console\Commands;

use App\Enums\EngagementStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Jobs\SyncIntegrationConnection;
use App\Models\IntegrationConnection;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Queue a sync pass for every connected integration, across all tenants —
 * the scheduled half of the two-way sync (FA-7). Each job carries its own
 * organization context. Archived engagements are read-only, so their
 * connections are skipped.
 */
class SyncIntegrations extends Command
{
    protected $signature = 'integrations:sync';

    protected $description = 'Queue a sync pass for every connected integration';

    public function handle(): int
    {
        $connections = IntegrationConnection::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('status', IntegrationConnectionStatus::Connected)
            ->whereHas('engagement', fn (Builder $query) => $query->whereNot('status', EngagementStatus::Archived))
            ->get();

        foreach ($connections as $connection) {
            SyncIntegrationConnection::dispatch($connection);
        }

        $this->info("Queued {$connections->count()} sync passes.");

        return self::SUCCESS;
    }
}
