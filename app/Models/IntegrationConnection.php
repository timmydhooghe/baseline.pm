<?php

namespace App\Models;

use App\Enums\IntegrationConnectionStatus;
use App\Enums\IntegrationProvider;
use App\Enums\SyncRunStatus;
use App\Integrations\ProviderClient;
use App\Integrations\SyncedIssue;
use App\Integrations\SyncedRelease;
use App\Jobs\SyncIntegrationConnection;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\IntegrationConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A per-engagement connection to Jira or Linear (FA-7). Credentials live on
 * the org-level IntegrationAccount the connection points at; the connection
 * itself holds only which project or team syncs into the engagement.
 * Disconnecting drops the account link but keeps the row and everything it
 * imported — history is governance evidence — and reconnecting (re-picking
 * an account) resyncs into the same record. Audit entries are written
 * explicitly for the governance-relevant moments (connect, disconnect,
 * reconnect) instead of via RecordsAuditLog, which would log noise on every
 * sync timestamp bump.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property string|null $integration_account_id
 * @property IntegrationProvider $provider
 * @property IntegrationConnectionStatus $status
 * @property string $external_project_key
 * @property string|null $connected_by
 * @property CarbonImmutable|null $connected_at
 * @property string|null $disconnected_by
 * @property CarbonImmutable|null $disconnected_at
 * @property CarbonImmutable|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read IntegrationAccount|null $account
 * @property-read User|null $connectedBy
 * @property-read User|null $disconnectedBy
 * @property-read Collection<int, SyncRun> $syncRuns
 * @property-read Collection<int, WorkItem> $workItems
 * @property-read Collection<int, Release> $releases
 */
#[Fillable(['provider', 'integration_account_id', 'external_project_key', 'connected_by', 'connected_at'])]
class IntegrationConnection extends Model
{
    /** @use HasFactory<IntegrationConnectionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'connected',
    ];

    /**
     * Stop syncing but retain the connection and its imported history. The
     * account link is dropped — reconnecting requires picking one again.
     */
    public function disconnect(?User $actor = null): void
    {
        if ($this->status !== IntegrationConnectionStatus::Connected) {
            throw new LogicException('Only a connected integration can be disconnected.');
        }

        $this->status = IntegrationConnectionStatus::Disconnected;
        $this->integration_account_id = null;
        $this->disconnected_by = $actor?->id;
        $this->disconnected_at = now();
        $this->save();

        AuditLog::record('integration.disconnected', $this, [
            'provider' => $this->provider->value,
            'external_project_key' => $this->external_project_key,
        ]);
    }

    /**
     * Reconnect a disconnected integration through an org account — the same
     * one as before or another of the same provider — and queue a resync
     * into the retained history (FA-7).
     */
    public function reconnect(IntegrationAccount $account, ?User $actor = null): void
    {
        if ($this->status !== IntegrationConnectionStatus::Disconnected) {
            throw new LogicException('Only a disconnected integration can be reconnected.');
        }

        if ($account->provider !== $this->provider) {
            throw new LogicException('A connection can only reconnect through an account of its own provider.');
        }

        $this->integration_account_id = $account->id;
        $this->status = IntegrationConnectionStatus::Connected;
        $this->connected_by = $actor?->id;
        $this->connected_at = now();
        $this->disconnected_by = null;
        $this->disconnected_at = null;
        $this->save();

        AuditLog::record('integration.reconnected', $this, [
            'provider' => $this->provider->value,
            'external_project_key' => $this->external_project_key,
        ]);

        SyncIntegrationConnection::dispatch($this);
    }

    /**
     * The API client for this connection, built from its account's
     * credentials and scoped to the connection's project or team.
     */
    public function client(): ProviderClient
    {
        $account = $this->account;

        if ($account === null) {
            throw new LogicException('A disconnected integration has no account to sync with.');
        }

        return $account->client($this->external_project_key);
    }

    /**
     * Open a sync run record — the visible trace of the pass that is about
     * to happen.
     */
    public function startSyncRun(): SyncRun
    {
        $run = new SyncRun;
        $run->organization_id = $this->organization_id;
        $run->integration_connection_id = $this->id;
        $run->status = SyncRunStatus::Running;
        $run->started_at = now();
        $run->save();

        return $run;
    }

    /**
     * Upsert the provider's issues and their worklogs into the engagement's
     * work history, keyed by external id so repeated syncs update in place.
     *
     * @param  list<SyncedIssue>  $issues
     * @return array{work_items: int, worklogs: int}
     */
    public function applySyncedIssues(array $issues): array
    {
        $worklogCount = 0;

        foreach ($issues as $issue) {
            $workItem = $this->workItems()->firstOrNew(['external_id' => $issue->externalId]);
            $workItem->organization_id = $this->organization_id;
            $workItem->engagement_id = $this->engagement_id;
            $workItem->source = $this->provider->workItemSource();
            $workItem->fill([
                'external_key' => $issue->externalKey,
                'external_url' => $issue->url,
                'title' => $issue->title,
                'external_status' => $issue->externalStatus,
                'state' => $issue->state,
                'type' => $issue->type,
                'assignee_name' => $issue->assigneeName,
                'estimate_value' => $issue->estimateValue,
                'estimate_unit' => $issue->estimateUnit,
                'external_updated_at' => $issue->externalUpdatedAt,
                'last_synced_at' => now(),
            ]);
            $workItem->save();

            foreach ($issue->worklogs as $worklog) {
                $record = $workItem->worklogs()->firstOrNew(['external_id' => $worklog->externalId]);
                $record->organization_id = $this->organization_id;
                $record->fill([
                    'author_name' => $worklog->authorName,
                    'seconds' => $worklog->seconds,
                    'logged_on' => $worklog->loggedOn,
                ]);
                $record->save();
                $worklogCount++;
            }
        }

        return ['work_items' => count($issues), 'worklogs' => $worklogCount];
    }

    /**
     * Upsert the provider's releases, keyed by external id.
     *
     * @param  list<SyncedRelease>  $releases
     */
    public function applySyncedReleases(array $releases): int
    {
        foreach ($releases as $release) {
            $record = $this->releases()->firstOrNew(['external_id' => $release->externalId]);
            $record->organization_id = $this->organization_id;
            $record->engagement_id = $this->engagement_id;
            $record->source = $this->provider->workItemSource();
            $record->fill([
                'name' => $release->name,
                'released' => $release->released,
                'released_on' => $release->releasedOn,
                'external_url' => $release->url,
            ]);
            $record->save();
        }

        return count($releases);
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /**
     * The org-level account whose credentials this connection syncs with.
     * Named `account`, not `connection` — Eloquent owns `$connection`.
     *
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'integration_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disconnectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disconnected_by');
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class)->orderByDesc('started_at');
    }

    /**
     * @return HasMany<WorkItem, $this>
     */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /**
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'status' => IntegrationConnectionStatus::class,
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
