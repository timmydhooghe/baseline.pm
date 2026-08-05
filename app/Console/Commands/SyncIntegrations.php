<?php

namespace App\Console\Commands;

use App\Enums\IntegrationConnectionStatus;
use App\Jobs\SyncIntegrationConnection;
use App\Models\IntegrationConnection;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Console\Command;

/**
 * Queue a sync pass for every connected integration, across all tenants —
 * the scheduled half of the two-way sync (FA-7). Each job carries its own
 * organization context.
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
            ->get();

        foreach ($connections as $connection) {
            SyncIntegrationConnection::dispatch($connection);
        }

        $this->info("Queued {$connections->count()} sync passes.");

        return self::SUCCESS;
    }
}
