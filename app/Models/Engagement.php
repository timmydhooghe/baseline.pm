<?php

namespace App\Models;

use App\Enums\BaselineStatus;
use App\Enums\EngagementStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Enums\WorkItemSource;
use App\Jobs\SyncIntegrationConnection;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use Database\Factories\EngagementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * An engagement delivered for a customer. Its lifecycle is the
 * EngagementStatus state machine; once archived it is read-only (still
 * searchable) and no longer counts toward the plan's engagement limit.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property string $name
 * @property EngagementStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Customer $customer
 * @property-read Collection<int, Baseline> $baselines
 * @property-read Collection<int, IntegrationConnection> $integrationConnections
 * @property-read Collection<int, WorkItem> $workItems
 * @property-read Collection<int, Release> $releases
 */
#[Fillable(['name'])]
class Engagement extends Model
{
    /** @use HasFactory<EngagementFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, RecordsAuditLog;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected static function booted(): void
    {
        static::updating(function (Engagement $engagement): void {
            if ($engagement->getRawOriginal('status') === EngagementStatus::Archived->value) {
                throw new LogicException('Archived engagements are read-only.');
            }
        });

        static::deleting(function (Engagement $engagement): void {
            if ($engagement->status === EngagementStatus::Archived) {
                throw new LogicException('Archived engagements are read-only.');
            }
        });
    }

    /**
     * Move the engagement to the next lifecycle status, refusing moves the
     * EngagementStatus state machine does not allow. Runs under a row lock
     * in one transaction: concurrent decisions would otherwise both read
     * the same status, each pass validation, and leave the engagement and
     * its submitted baseline pointing in different directions.
     */
    public function transitionTo(EngagementStatus $target): void
    {
        DB::transaction(function () use ($target): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();
            $this->refresh();

            $from = $this->status;

            if (! $from->canTransitionTo($target)) {
                throw new LogicException("An engagement cannot move from [{$from->value}] to [{$target->value}].");
            }

            $this->status = $target;
            $this->save();

            AuditLog::record('engagement.transitioned', $this, [
                'from' => $from->value,
                'to' => $target->value,
            ]);

            $this->syncSubmittedBaseline($from, $target);
        });
    }

    /**
     * Start the next baseline draft, pinning the organization's current rate
     * card version. Serialized under a lock so concurrent requests cannot
     * claim the same version number or open two drafts.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function startBaseline(array $attributes, ?User $author = null): Baseline
    {
        return DB::transaction(function () use ($attributes, $author): Baseline {
            /*
             * PostgreSQL refuses FOR UPDATE on aggregate queries, so the
             * version read is serialized by locking the engagement row.
             */
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            if (! in_array($this->status, [EngagementStatus::Draft, EngagementStatus::PreparingBaseline], true)) {
                throw ValidationException::withMessages([
                    'baseline' => __('A baseline can only be drafted while the engagement is preparing one. Later versions come from approved change requests.'),
                ]);
            }

            if ($this->baselines()->whereNot('status', BaselineStatus::Approved)->exists()) {
                throw ValidationException::withMessages([
                    'baseline' => __('This engagement already has a baseline in progress.'),
                ]);
            }

            /*
             * Without a published rate card the pinned version would stay
             * null forever and the draft could never be priced — commercials
             * validate roles against the pinned version only.
             */
            $rateCardVersion = $this->organization->currentRateCardVersion();

            if ($rateCardVersion === null) {
                throw ValidationException::withMessages([
                    'baseline' => __('Publish a rate card before drafting a baseline — cost budgets derive from the version pinned at creation.'),
                ]);
            }

            $baseline = new Baseline([...$attributes, 'created_by' => $author?->id]);
            $baseline->organization_id = $this->organization_id;
            $baseline->engagement_id = $this->id;
            $baseline->version = (int) $this->baselines()->max('version') + 1;
            $baseline->rate_card_version_id = $rateCardVersion->id;
            $baseline->save();

            if ($this->status === EngagementStatus::Draft) {
                $this->transitionTo(EngagementStatus::PreparingBaseline);
            }

            return $baseline;
        });
    }

    /**
     * The baseline currently being drafted or reviewed, if any.
     */
    public function openBaseline(): ?Baseline
    {
        return $this->baselines()
            ->whereNot('status', BaselineStatus::Approved)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * The approved baseline the engagement currently executes against.
     */
    public function approvedBaseline(): ?Baseline
    {
        return $this->baselines()
            ->where('status', BaselineStatus::Approved)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * The baseline execution work maps against: the approved version when
     * one exists, otherwise the one being drafted.
     */
    public function currentBaseline(): ?Baseline
    {
        return $this->approvedBaseline() ?? $this->openBaseline();
    }

    /**
     * Connect an execution tool through one of the organization's accounts,
     * or reconnect a previously disconnected one — the retained history
     * resyncs instead of starting over (FA-7). The first sync is queued
     * immediately.
     */
    public function connectIntegration(
        IntegrationAccount $account,
        string $externalProjectKey,
        ?User $actor = null,
    ): IntegrationConnection {
        $provider = $account->provider;

        if ($this->status === EngagementStatus::Archived) {
            throw ValidationException::withMessages([
                'integration_account_id' => __('Archived engagements are read-only.'),
            ]);
        }

        $existing = $this->integrationConnections()->firstWhere('provider', $provider);

        if ($existing?->status === IntegrationConnectionStatus::Connected) {
            throw ValidationException::withMessages([
                'integration_account_id' => __(':provider is already connected to this engagement.', ['provider' => $provider->label()]),
            ]);
        }

        if ($existing !== null) {
            $existing->external_project_key = $externalProjectKey;
            $existing->reconnect($account, $actor);

            return $existing;
        }

        $connection = new IntegrationConnection([
            'provider' => $provider,
            'integration_account_id' => $account->id,
            'external_project_key' => $externalProjectKey,
            'connected_by' => $actor?->id,
            'connected_at' => now(),
        ]);
        $connection->organization_id = $this->organization_id;
        $connection->engagement_id = $this->id;
        $connection->save();

        AuditLog::record('integration.connected', $connection, [
            'provider' => $provider->value,
            'external_project_key' => $externalProjectKey,
        ]);

        SyncIntegrationConnection::dispatch($connection);

        return $connection;
    }

    /**
     * Record a work item by hand — the standalone execution mode (FA-4).
     * Manual items live alongside synced ones, so an engagement can upgrade
     * to an integration later without losing its history.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addManualWorkItem(array $attributes, ?User $author = null): WorkItem
    {
        if ($this->status === EngagementStatus::Archived) {
            throw ValidationException::withMessages([
                'title' => __('Archived engagements are read-only.'),
            ]);
        }

        $workItem = new WorkItem([...$attributes, 'source' => WorkItemSource::Manual, 'created_by' => $author?->id]);
        $workItem->organization_id = $this->organization_id;
        $workItem->engagement_id = $this->id;
        $workItem->save();

        AuditLog::record('work_item.recorded', $workItem, [
            'title' => $workItem->title,
        ]);

        return $workItem;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Baseline, $this>
     */
    public function baselines(): HasMany
    {
        return $this->hasMany(Baseline::class);
    }

    /**
     * @return HasMany<IntegrationConnection, $this>
     */
    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
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
     * Keep the submitted baseline in step when the engagement is moved
     * through the lifecycle directly: leaving awaiting-approval towards
     * Active approves it, moving back to preparing withdraws it. The
     * baseline's own methods flip its status before transitioning the
     * engagement, so this never runs twice for one decision.
     */
    protected function syncSubmittedBaseline(EngagementStatus $from, EngagementStatus $target): void
    {
        if ($from !== EngagementStatus::AwaitingBaselineApproval) {
            return;
        }

        $submitted = $this->baselines()
            ->where('status', BaselineStatus::AwaitingApproval)
            ->orderByDesc('version')
            ->first();

        if ($submitted === null) {
            return;
        }

        if ($target === EngagementStatus::Active) {
            $submitted->approve();
        } elseif ($target === EngagementStatus::PreparingBaseline) {
            $submitted->returnToDraft('withdrawn');
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EngagementStatus::class,
        ];
    }
}
