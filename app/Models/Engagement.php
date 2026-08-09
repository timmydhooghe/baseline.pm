<?php

namespace App\Models;

use App\Enums\BaselineStatus;
use App\Enums\DeliverableStatus;
use App\Enums\EngagementStatus;
use App\Enums\FinalAcceptanceStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Enums\WorkItemSource;
use App\Jobs\SyncIntegrationConnection;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use App\Notifications\FinalAcceptanceSubmitted;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\EngagementFactory;
use DateTimeInterface;
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
 * @property-read Collection<int, ChangeRequest> $changeRequests
 * @property-read Collection<int, Release> $releases
 * @property-read Collection<int, Deliverable> $deliverables
 * @property-read Collection<int, FinalAcceptance> $finalAcceptances
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

            /*
             * FA-24: Completed sits behind the final acceptance gate.
             * "Accepted" always means signed — the customer's recorded
             * acceptance, never an internal shortcut.
             */
            if ($target === EngagementStatus::Completed
                && $this->finalAcceptances()->where('status', FinalAcceptanceStatus::Accepted)->doesntExist()) {
                throw new LogicException('Completion requires the customer\'s signed final acceptance.');
            }

            $this->status = $target;
            $this->save();

            AuditLog::record('engagement.transitioned', $this, [
                'from' => $from->value,
                'to' => $target->value,
            ]);

            $this->syncSubmittedBaseline($from, $target);
            $this->syncFinalAcceptance($from, $target);
        });
    }

    /**
     * Submit the engagement for final acceptance (FA-24): the gate before
     * Completed. Requires every deliverable to be signed off already, then
     * freezes twin snapshots of the accepted record, moves the engagement to
     * awaiting final acceptance and notifies every stakeholder with approval
     * rights — the completion itself arrives only with their signature.
     */
    public function submitForFinalAcceptance(DateTimeInterface|string $respondBy, ?User $submitter = null): FinalAcceptance
    {
        $respondBy = CarbonImmutable::parse($respondBy)->endOfDay();

        $finalAcceptance = DB::transaction(function () use ($respondBy, $submitter): FinalAcceptance {
            self::query()->whereKey($this->id)->lockForUpdate()->first();
            $this->refresh();

            if (! $this->status->canTransitionTo(EngagementStatus::AwaitingFinalAcceptance)) {
                throw new LogicException("An engagement cannot be submitted for final acceptance from [{$this->status->value}].");
            }

            if ($respondBy->isPast()) {
                throw ValidationException::withMessages([
                    'respond_by' => __('The respond-by deadline must lie in the future.'),
                ]);
            }

            $open = $this->deliverables()->whereNot('status', DeliverableStatus::Accepted)->count();

            if ($open > 0) {
                throw ValidationException::withMessages([
                    'respond_by' => trans_choice(
                        '{1}Final acceptance assembles from signed deliverables — one still awaits its signature.|[2,*]Final acceptance assembles from signed deliverables — :count still await their signature.',
                        $open,
                        ['count' => $open],
                    ),
                ]);
            }

            $finalAcceptance = new FinalAcceptance([
                'respond_by' => $respondBy,
                'submitted_at' => now(),
                'created_by' => $submitter?->id,
            ]);
            $finalAcceptance->organization_id = $this->organization_id;
            $finalAcceptance->engagement_id = $this->id;
            $finalAcceptance->save();

            $review = Snapshot::capture($finalAcceptance, $finalAcceptance->snapshotPayload(internal: true), $submitter);
            $customer = Snapshot::capture($finalAcceptance, $finalAcceptance->snapshotPayload(internal: false), $submitter);

            $finalAcceptance->review_snapshot_id = $review->id;
            $finalAcceptance->customer_snapshot_id = $customer->id;
            $finalAcceptance->save();

            AuditLog::record('final_acceptance.submitted', $finalAcceptance, [
                'engagement' => $this->name,
                'respond_by' => $respondBy->toDateString(),
                'accepted_value' => $this->acceptedValue()->format(),
                'review_snapshot_id' => $review->id,
                'customer_snapshot_id' => $customer->id,
            ]);

            $this->transitionTo(EngagementStatus::AwaitingFinalAcceptance);

            return $finalAcceptance;
        });

        foreach ($finalAcceptance->approvers() as $approver) {
            $approver->notify(new FinalAcceptanceSubmitted($finalAcceptance));
        }

        return $finalAcceptance;
    }

    /**
     * The most recent final acceptance request, whatever its outcome.
     */
    public function currentFinalAcceptance(): ?FinalAcceptance
    {
        return $this->finalAcceptances()->latest('created_at')->first();
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
     * Raise a change request by hand (FA-11): a steering call, an email or
     * another channel surfaced a change that never touched the triage inbox.
     * Drift-born drafts come from WorkItem::triage() instead.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function draftChangeRequest(array $attributes, ?User $author = null): ChangeRequest
    {
        if ($this->status === EngagementStatus::Archived) {
            throw ValidationException::withMessages([
                'title' => __('Archived engagements are read-only.'),
            ]);
        }

        $changeRequest = new ChangeRequest([...$attributes, 'created_by' => $author?->id]);
        $changeRequest->organization_id = $this->organization_id;
        $changeRequest->engagement_id = $this->id;
        $changeRequest->save();

        AuditLog::record('change_request.drafted', $changeRequest, [
            'title' => $changeRequest->title,
            'origin' => $changeRequest->origin?->value,
            'drafted_by' => $author?->name,
        ]);

        return $changeRequest;
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
     * @return HasMany<ChangeRequest, $this>
     */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }

    /**
     * Unmapped work still waiting for a triage decision (FA-9) — the inbox
     * queue whose potential price is the engagement's unbilled risk (FA-10).
     *
     * @return HasMany<WorkItem, $this>
     */
    public function driftWorkItems(): HasMany
    {
        return $this->workItems()
            ->whereDoesntHave('link')
            ->whereNull('triage_status');
    }

    /**
     * The aggregate commercial exposure of unresolved drift (FA-10): every
     * untriaged unmapped item priced at the current baseline's blended day
     * rates. Items without priceable effort are counted as unpriced —
     * visible risk that carries no number yet, never a made-up one.
     *
     * @return array{count: int, unpriced: int, cost: Money, price: Money}
     */
    public function unbilledRisk(): array
    {
        $rates = $this->currentBaseline()?->blendedDayRates();
        $items = $this->driftWorkItems()->with('worklogs')->get();

        $cost = Money::zero();
        $price = Money::zero();
        $unpriced = 0;

        foreach ($items as $item) {
            $priced = $item->priceEffort($rates);

            if ($priced['cost'] === null || $priced['price'] === null) {
                $unpriced++;

                continue;
            }

            $cost = $cost->add($priced['cost']);
            $price = $price->add($priced['price']);
        }

        return ['count' => $items->count(), 'unpriced' => $unpriced, 'cost' => $cost, 'price' => $price];
    }

    /**
     * The signed-off value on the rail (FA-23): every accepted deliverable's
     * value as frozen at the moment of signature — accepted always means
     * signed, so this line only ever grows by customer decision.
     */
    public function acceptedValue(): Money
    {
        return Money::fromCents((int) $this->deliverables()
            ->where('status', DeliverableStatus::Accepted)
            ->sum('accepted_value_cents'));
    }

    /**
     * The position rail summary (FA-10, FA-23): what is contracted, what the
     * customer has signed off, and what the unresolved drift would be worth,
     * always visible beside the engagement's pages. The waterfall's remaining
     * lines — burned, pending CRs — arrive with their own features (FA-14,
     * FA-16).
     *
     * The unbilled-risk price derives from sell rates, so it follows the
     * rate card policy: callers pass whether the viewer may read commercial
     * figures, and for everyone else the price is structurally absent while
     * the queue size stays visible. The contracted value is not stripped —
     * members already see it on the baseline page.
     *
     * @return array<string, mixed>
     */
    public function positionSummary(bool $withCommercials): array
    {
        $approved = $this->approvedBaseline();
        $risk = $this->unbilledRisk();

        return [
            'engagementId' => $this->id,
            'contracted' => $approved?->contract_value->toArray(),
            'baselineVersion' => $approved?->version,
            'accepted' => [
                'count' => $this->deliverables()->where('status', DeliverableStatus::Accepted)->count(),
                'total' => $this->deliverables()->count(),
                'value' => $this->acceptedValue()->toArray(),
            ],
            'unbilledRisk' => [
                'count' => $risk['count'],
                'unpriced' => $risk['unpriced'],
                'price' => $withCommercials ? $risk['price']->toArray() : null,
            ],
        ];
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
     * Keep the open final acceptance in step when the engagement is moved
     * back to Active by hand: the request is withdrawn, its frozen snapshots
     * stay on record. The decision paths flip the request's status before
     * transitioning the engagement, so this never runs twice for one
     * decision.
     */
    protected function syncFinalAcceptance(EngagementStatus $from, EngagementStatus $target): void
    {
        if ($from !== EngagementStatus::AwaitingFinalAcceptance || $target !== EngagementStatus::Active) {
            return;
        }

        $this->finalAcceptances()
            ->where('status', FinalAcceptanceStatus::AwaitingResponse)
            ->latest('created_at')
            ->first()
            ?->withdraw();
    }

    /**
     * @return HasMany<Deliverable, $this>
     */
    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    /**
     * @return HasMany<FinalAcceptance, $this>
     */
    public function finalAcceptances(): HasMany
    {
        return $this->hasMany(FinalAcceptance::class);
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
