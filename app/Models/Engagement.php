<?php

namespace App\Models;

use App\Actions\Governance\ProposeDecisionsFromTranscript;
use App\Actions\Money\MarginForecast;
use App\Actions\Money\WeeklyBurnSuggestion;
use App\Actions\Reporting\WeeklyReportDraft;
use App\Enums\BaselineStatus;
use App\Enums\BurnSource;
use App\Enums\ChangeRequestStatus;
use App\Enums\DeliverableStatus;
use App\Enums\DependencyParty;
use App\Enums\DependencyStatus;
use App\Enums\EngagementStatus;
use App\Enums\FinalAcceptanceStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Enums\RiskStatus;
use App\Enums\WorkItemSource;
use App\Jobs\SyncIntegrationConnection;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use App\Notifications\FinalAcceptanceSubmitted;
use App\Notifications\WeeklyReportPublished;
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
use Illuminate\Support\Collection as SupportCollection;
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
 * @property-read Collection<int, Decision> $decisions
 * @property-read Collection<int, Risk> $risks
 * @property-read Collection<int, Dependency> $dependencies
 * @property-read Collection<int, BurnWeek> $burnWeeks
 * @property-read Collection<int, Report> $reports
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

    /**
     * The memoized margin forecast for this request, and whether the memo
     * carries the "why it moved" attribution.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $forecast = null;

    protected bool $forecastCarriesAttribution = false;

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
        if ($this->relationLoaded('finalAcceptances')) {
            return $this->finalAcceptances->sortByDesc('created_at')->first();
        }

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
     * The approved baseline the engagement currently executes against. Reads
     * the loaded relation when a caller eager-loaded it — the portfolio
     * dashboard asks this once per derived figure, and each answer must not
     * cost a query of its own.
     */
    public function approvedBaseline(): ?Baseline
    {
        if ($this->relationLoaded('baselines')) {
            return $this->baselines
                ->filter(fn (Baseline $baseline): bool => $baseline->status === BaselineStatus::Approved)
                ->sortByDesc('version')
                ->first();
        }

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

        $this->guardWritable(__('Archived engagements are read-only.'), 'integration_account_id');

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
        $this->guardWritable(__('Archived engagements are read-only.'), 'title');

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
     * Scope-creep-born drafts come from WorkItem::triage() instead.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function draftChangeRequest(array $attributes, ?User $author = null): ChangeRequest
    {
        $this->guardWritable(__('Archived engagements are read-only.'), 'title');

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
     * Record a decision in the ledger (FA-18). Entries start as drafts —
     * whether typed here or extracted from a transcript — and only enter the
     * ledger when confirmed, so a half-remembered outcome never becomes
     * something the engagement is held to.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordDecision(array $attributes, ?User $author = null): Decision
    {
        $this->guardWritable(__('Archived engagements are read-only.'), 'title');

        $decision = new Decision([...$attributes, 'created_by' => $author?->id]);
        $decision->organization_id = $this->organization_id;
        $decision->engagement_id = $this->id;
        $decision->save();

        AuditLog::record('decision.drafted', $decision, [
            'decision' => $decision->title,
            'source' => $decision->source->value,
            'drafted_by' => $author?->name,
        ]);

        return $decision;
    }

    /**
     * Propose decision drafts from a meeting transcript (FA-18). The
     * extraction proposes, never decides: every result is a draft carrying
     * the excerpt it came from, and nothing reaches the ledger until a human
     * reads it and confirms.
     *
     * @return Collection<int, Decision>
     */
    public function proposeDecisionsFromTranscript(string $transcript, ?User $author = null): Collection
    {
        $proposals = app(ProposeDecisionsFromTranscript::class)($transcript);

        return new Collection(array_map(
            fn (array $attributes): Decision => $this->recordDecision($attributes, $author),
            $proposals,
        ));
    }

    /**
     * Raise a risk on the register (FA-19), pinning the rate card version its
     * exposure prices against — the approved baseline's, so risk exposure and
     * cost budget derive from the same rates. The opening rating is frozen as
     * the first revision, giving the history something to be read against.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerRisk(array $attributes, ?User $author = null): Risk
    {
        $this->guardWritable(__('Archived engagements are read-only.'), 'title');

        $risk = new Risk([...$attributes, 'created_by' => $author?->id]);
        $risk->organization_id = $this->organization_id;
        $risk->engagement_id = $this->id;
        $pinned = $this->approvedBaseline()?->rate_card_version_id;
        $risk->rate_card_version_id = $pinned ?? $this->organization->currentRateCardVersion()?->id;
        $risk->save();

        $risk->recordRevision($author);

        AuditLog::record('risk.registered', $risk, [
            'risk' => $risk->title,
            'probability' => $risk->probability->value,
            'impact' => $risk->impact->value,
            'score' => $risk->score(),
            'registered_by' => $author?->name,
        ]);

        return $risk;
    }

    /**
     * Register a dependency (FA-20): something the engagement waits for, owed
     * by a named person, due on a date.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerDependency(array $attributes, ?User $author = null): Dependency
    {
        $this->guardWritable(__('Archived engagements are read-only.'), 'title');

        $dependency = new Dependency([...$attributes, 'created_by' => $author?->id]);
        $dependency->organization_id = $this->organization_id;
        $dependency->engagement_id = $this->id;
        $dependency->save();

        AuditLog::record('dependency.registered', $dependency, [
            'dependency' => $dependency->title,
            'party' => $dependency->party->value,
            'responsible' => $dependency->responsibleName(),
            'required_on' => $dependency->required_on->toDateString(),
            'registered_by' => $author?->name,
        ]);

        return $dependency;
    }

    /**
     * Record a week of burn (FA-16): days per person or profile, priced at
     * the pinned rate card. Recording freezes the week — the rows never
     * change again — and recording a week that is already on record files a
     * correction: the new entry becomes current and the earlier one is
     * marked superseded rather than rewritten.
     *
     * Serialized under a row lock: two managers recording the same week
     * would otherwise each find no current entry, both write one, and leave
     * cost-to-date double counting a week nobody worked twice.
     *
     * Provenance is derived here, never accepted: a caller can claim a figure
     * came from logged time, and the row it lands on is immutable, so the
     * claim is checked against the worklogs and the plan before it is frozen.
     *
     * @param  list<array{rate_card_role_id: string, days: float|string, person_name?: string|null, user_id?: string|null}>  $lines
     */
    public function recordBurnWeek(DateTimeInterface|string $week, array $lines, ?User $actor = null, ?string $note = null): BurnWeek
    {
        $this->guardWritable(__('Archived engagements are read-only.'), 'week_start');

        $weekStart = BurnWeek::startOfWeekFor($week);

        if ($weekStart->isFuture()) {
            throw ValidationException::withMessages([
                'week_start' => __('A week can only be recorded once it has started.'),
            ]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => __('A recorded week needs at least one line — an empty week is not the same as a week nobody worked.'),
            ]);
        }

        /*
         * Every euro traces to a published rate card (FA-2). The approved
         * baseline's pin is the one cost budget and margin are read against;
         * before approval the organization's current version stands in, so a
         * mobilisation week is not lost for want of a signature.
         */
        $pinned = $this->approvedBaseline()?->rateCardVersion;
        $version = $pinned ?? $this->organization->currentRateCardVersion();

        if ($version === null) {
            throw ValidationException::withMessages([
                'lines' => __('Publish a rate card before recording burn — cost derives from role rates, never from a typed amount.'),
            ]);
        }

        /*
         * Priced before anything is written: the week's frozen total has to
         * be right the first time, because the row it lands on can never be
         * updated again.
         */
        $priced = $this->priceBurnLines(
            $lines,
            $version->roles->keyBy('id'),
            app(WeeklyBurnSuggestion::class)->provenance($this, $weekStart),
        );
        $total = array_reduce(
            $priced,
            fn (Money $sum, array $line): Money => $sum->add($line['cost']),
            Money::zero(),
        );

        return DB::transaction(function () use ($weekStart, $priced, $total, $actor, $note, $version): BurnWeek {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $superseded = $this->burnWeeks()
                ->whereNull('superseded_at')
                ->whereDate('week_start', $weekStart->toDateString())
                ->first();

            $burnWeek = new BurnWeek([
                'week_start' => $weekStart,
                'note' => $note,
                'recorded_at' => now(),
                'recorded_by' => $actor?->id,
            ]);
            $burnWeek->organization_id = $this->organization_id;
            $burnWeek->engagement_id = $this->id;
            $burnWeek->rate_card_version_id = $version->id;
            $burnWeek->cost = $total;
            $burnWeek->save();

            foreach ($priced as $line) {
                $burnWeek->entries()->create([...$line, 'organization_id' => $this->organization_id]);
            }

            if ($superseded !== null) {
                $superseded->superseded_at = now();
                $superseded->superseded_by_id = $burnWeek->id;
                $superseded->save();
            }

            AuditLog::record($superseded === null ? 'burn_week.recorded' : 'burn_week.corrected', $burnWeek, [
                'week_start' => $weekStart->toDateString(),
                'lines' => count($priced),
                'days' => array_sum(array_column($priced, 'days')),
                'cost' => $total->format(),
                'rate_card_version' => $version->version,
                'corrects' => $superseded?->id,
                'previous_cost' => $superseded?->cost->format(),
                'note' => $note,
                'recorded_by' => $actor?->name,
            ]);

            /*
             * The week that just landed moved cost to date, so every derived
             * figure memoized before it is now a stale reading of the ledger.
             */
            $this->forecast = null;

            return $burnWeek->load('entries.role');
        });
    }

    /**
     * Turn the submitted lines into priced burn entries, refusing anything
     * that would put a figure on the ledger nobody can trace: a role from
     * another rate card version, days that were not spent, the same person
     * booked twice in one week, or one person's week adding up to more than
     * seven days across their lines.
     *
     * @param  list<array{rate_card_role_id: string, days: float|string, person_name?: string|null, user_id?: string|null}>  $lines
     * @param  SupportCollection<string, RateCardRole>  $roles
     * @param  array{worklog: array<string, float>, progress: array<string, float>}  $provenance
     * @return list<array<string, mixed>>
     */
    protected function priceBurnLines(array $lines, SupportCollection $roles, array $provenance): array
    {
        $priced = [];
        $seen = [];
        $daysPerPerson = [];

        foreach ($lines as $index => $line) {
            $role = $roles->get($line['rate_card_role_id']);

            if (! $role instanceof RateCardRole) {
                throw ValidationException::withMessages([
                    "lines.{$index}.rate_card_role_id" => __('Pick a role from the rate card version this engagement is priced against.'),
                ]);
            }

            $days = round((float) $line['days'], 2);

            if ($days <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.days" => __('A burn line records days actually spent — leave the line out instead.'),
                ]);
            }

            $person = $line['person_name'] ?? null;
            $person = is_string($person) && mb_trim($person) !== '' ? mb_trim($person) : null;
            $folded = BurnEntry::normalizePerson($person);
            $key = $role->id.'|'.($folded ?? '');

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.rate_card_role_id" => __('This person and profile already have a line this week — record their days once.'),
                ]);
            }

            $seen[$key] = true;

            if ($folded !== null) {
                $daysPerPerson[$folded] = ($daysPerPerson[$folded] ?? 0.0) + $days;

                if ($daysPerPerson[$folded] > 7) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.days" => __('A week holds seven days — :person cannot have spent more across their lines.', [
                            'person' => $person,
                        ]),
                    ]);
                }
            }

            $priced[] = [
                'rate_card_role_id' => $role->id,
                'role_name' => $role->name,
                'user_id' => $this->resolveBurnUserId($line['user_id'] ?? null, $person),
                'person_name' => $person,
                'days' => $days,
                'source' => $this->deriveBurnSource($provenance, $role->name, $person, $days),
                'cost_per_day' => $role->cost_per_day,
                'cost' => Money::fromCents((int) round($days * $role->cost_per_day->amount)),
            ];
        }

        return $priced;
    }

    /**
     * Where a burn figure actually came from (FA-16). A line only counts as
     * logged time when that person really logged those days that week, and
     * only counts as a progress estimate when it still matches the estimate
     * the plan produces. Everything else — an edited suggestion, a figure
     * against a profile that logged nothing, a number a client merely
     * labelled — is what it is: a manual entry.
     *
     * Derived rather than accepted because the row it lands on is immutable:
     * a wrong provenance recorded here can never be corrected in place.
     *
     * @param  array{worklog: array<string, float>, progress: array<string, float>}  $provenance
     */
    protected function deriveBurnSource(array $provenance, string $roleName, ?string $person, float $days): BurnSource
    {
        $matches = fn (?float $derived): bool => $derived !== null && abs($derived - $days) < 0.005;

        return match (true) {
            $person !== null && $matches($provenance['worklog'][$person] ?? null) => BurnSource::Worklog,
            $person === null && $matches($provenance['progress'][$roleName] ?? null) => BurnSource::Progress,
            default => BurnSource::Manual,
        };
    }

    /**
     * The colleague a line is attributed to, kept only while the name still
     * belongs to them. Editing a prefilled row from one person to another
     * leaves the old reference behind, and a week that says "Bob" while
     * pointing at Sara's record is worse than one that names Bob and points
     * at nobody — the name is what a human typed, the link is an inference.
     */
    protected function resolveBurnUserId(?string $userId, ?string $person): ?string
    {
        if ($userId === null || $person === null) {
            return null;
        }

        $user = User::query()
            ->where('organization_id', $this->organization_id)
            ->whereKey($userId)
            ->first();

        return $user?->name === $person ? $user->id : null;
    }

    /**
     * The burn weeks the money reads: the current recording of each week,
     * newest first. Superseded entries stay on the ledger as the trail of
     * what was corrected.
     *
     * @return HasMany<BurnWeek, $this>
     */
    public function currentBurnWeeks(): HasMany
    {
        return $this->burnWeeks()->whereNull('superseded_at');
    }

    /**
     * Cost-to-date (FA-15): every recorded week's frozen cost. This is the
     * "recorded burn" half of forecast-at-completion.
     */
    public function recordedBurn(): Money
    {
        return Money::fromCents((int) $this->currentBurnWeeks()->sum('cost_cents'));
    }

    /**
     * The weeks of the engagement that have finished without anybody
     * recording them (FA-16) — the queue Today puts in front of the delivery
     * manager. The current week is not late until it is over, and a
     * completed or archived engagement has stopped burning.
     *
     * @return list<CarbonImmutable>
     */
    public function unrecordedBurnWeeks(?DateTimeInterface $asOf = null): array
    {
        $recorded = ($this->relationLoaded('burnWeeks')
            ? $this->burnWeeks->whereNull('superseded_at')->pluck('week_start')
            : $this->currentBurnWeeks()->pluck('week_start'))
            ->map(fn (mixed $date): string => BurnWeek::startOfWeekFor($date)->toDateString())
            ->all();

        return array_values(array_filter(
            $this->finishedWeeks($asOf),
            fn (CarbonImmutable $week): bool => ! in_array($week->toDateString(), $recorded, true),
        ));
    }

    /**
     * The weeks whose report has not been published (FA-25, FA-26) — the
     * report-draft queue Today surfaces. A draft is derived, never stored, so
     * a due week is a draft by existing.
     *
     * @return list<CarbonImmutable>
     */
    public function dueReportWeeks(?DateTimeInterface $asOf = null): array
    {
        $published = ($this->relationLoaded('reports')
            ? $this->reports->pluck('week_start')
            : $this->reports()->pluck('week_start'))
            ->map(fn (mixed $date): string => BurnWeek::startOfWeekFor($date)->toDateString())
            ->all();

        return array_values(array_filter(
            $this->finishedWeeks($asOf),
            fn (CarbonImmutable $week): bool => ! in_array($week->toDateString(), $published, true),
        ));
    }

    /**
     * The finished weeks of the execution window: from the approved
     * baseline's start to the most recently completed week, clamped to the
     * planned end. The current week is never late until it is over, and a
     * completed or archived engagement has stopped accruing weeks. This is
     * the calendar both weekly ledgers — burn and reports — are read against,
     * so a week cannot be due in one and unknown to the other.
     *
     * @return list<CarbonImmutable>
     */
    protected function finishedWeeks(?DateTimeInterface $asOf = null): array
    {
        $baseline = $this->approvedBaseline();

        if ($baseline === null || in_array($this->status, [EngagementStatus::Completed, EngagementStatus::Archived], true)) {
            return [];
        }

        $from = BurnWeek::startOfWeekFor($baseline->start_date);
        $until = BurnWeek::startOfWeekFor($asOf ?? now())->subWeek();
        $planned = BurnWeek::startOfWeekFor($baseline->end_date);

        if ($planned->lessThan($until)) {
            $until = $planned;
        }

        $weeks = [];

        for ($week = $from; ! $week->greaterThan($until); $week = $week->addWeek()) {
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * Publish the week's report (FA-26): derive both variants from evidence,
     * freeze them as twin snapshots — the customer one built without cost or
     * margin — and send every stakeholder their personally signed link. One
     * report per week, immutable once out: a correction would be a new claim
     * about a week the customer was already told about, and the trail must
     * show what was actually sent.
     *
     * Serialized under the engagement row lock so two managers publishing the
     * same week cannot both find it unpublished and send twice.
     */
    public function publishWeeklyReport(DateTimeInterface|string $week, ?User $publisher = null): Report
    {
        $this->guardWritable(__('Archived engagements are read-only.'), 'week_start');

        $weekStart = BurnWeek::startOfWeekFor($week);

        if ($weekStart->isFuture()) {
            throw ValidationException::withMessages([
                'week_start' => __('A report covers a week that has started — this one has not.'),
            ]);
        }

        $baseline = $this->approvedBaseline();

        if ($baseline === null) {
            throw ValidationException::withMessages([
                'week_start' => __('Weekly reports read the engagement against its approved baseline — approve one first.'),
            ]);
        }

        /*
         * The reporting window opens with the baseline. A week before it
         * would freeze — and mail out — a record of a week the engagement
         * was not being executed. Weeks past the planned end stay
         * publishable: an overrun is still a week the customer lived.
         */
        if ($weekStart->lessThan(BurnWeek::startOfWeekFor($baseline->start_date))) {
            throw ValidationException::withMessages([
                'week_start' => __('Reporting starts with the baseline — the week of :week lies before it.', [
                    'week' => BurnWeek::labelFor($weekStart),
                ]),
            ]);
        }

        $draft = app(WeeklyReportDraft::class);

        $report = DB::transaction(function () use ($draft, $weekStart, $publisher): Report {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            if ($this->reports()->whereDate('week_start', $weekStart->toDateString())->exists()) {
                throw ValidationException::withMessages([
                    'week_start' => __('The report for the week of :week is already published — published reports are immutable.', [
                        'week' => BurnWeek::labelFor($weekStart),
                    ]),
                ]);
            }

            $report = new Report([
                'week_start' => $weekStart,
                'published_at' => now(),
                'published_by' => $publisher?->id,
                'published_by_name' => $publisher?->name,
            ]);
            $report->organization_id = $this->organization_id;
            $report->engagement_id = $this->id;
            $report->save();

            $review = Snapshot::capture($report, $draft($this, $weekStart, internal: true), $publisher);
            $customer = Snapshot::capture($report, $draft($this, $weekStart, internal: false), $publisher);

            $report->review_snapshot_id = $review->id;
            $report->customer_snapshot_id = $customer->id;
            $report->save();

            AuditLog::record('report.published', $report, [
                'week_start' => $weekStart->toDateString(),
                'week' => BurnWeek::labelFor($weekStart),
                'review_snapshot_id' => $review->id,
                'customer_snapshot_id' => $customer->id,
                'published_by' => $publisher?->name,
            ]);

            return $report;
        });

        foreach ($this->customer->stakeholders as $stakeholder) {
            $stakeholder->notify(new WeeklyReportPublished($report));
        }

        return $report;
    }

    /**
     * The risks that belong in front of somebody today (FA-19, FA-25): live
     * high-probability, high-impact entries, plus any live risk whose last
     * re-rating made it worse. Ordered worst first.
     *
     * @return Collection<int, Risk>
     */
    public function escalatedRisks(): Collection
    {
        $live = $this->relationLoaded('risks')
            ? $this->risks->filter(fn (Risk $risk): bool => in_array($risk->status, [RiskStatus::Open, RiskStatus::Mitigating], true))
            : $this->risks()
                ->whereIn('status', [RiskStatus::Open, RiskStatus::Mitigating])
                ->with(['revisions', 'exposures.role', 'owner'])
                ->get();

        return $live
            ->filter(fn (Risk $risk): bool => $risk->isEscalated() || $risk->isWorsening())
            ->sortByDesc(fn (Risk $risk): int => $risk->score())
            ->values();
    }

    /**
     * The engagement's risk exposure (FA-17, FA-19): what the live register
     * is worth in effort at risk, and the probability-weighted figure that
     * rolls into the margin risk band. Cost-derived, so internal only.
     *
     * @return array{count: int, escalated: int, exposure: Money, weighted: Money}
     */
    public function riskExposure(): array
    {
        $live = $this->risks()
            ->whereIn('status', [RiskStatus::Open, RiskStatus::Mitigating])
            ->with(['revisions', 'exposures.role'])
            ->get();

        return [
            'count' => $live->count(),
            'escalated' => $live->filter(fn (Risk $risk): bool => $risk->isEscalated() || $risk->isWorsening())->count(),
            'exposure' => $live->reduce(
                fn (Money $sum, Risk $risk): Money => $sum->add($risk->exposure()),
                Money::zero(),
            ),
            'weighted' => $live->reduce(
                fn (Money $sum, Risk $risk): Money => $sum->add($risk->weightedExposure()),
                Money::zero(),
            ),
        ];
    }

    /**
     * The items the customer still owes (FA-20, FA-27) — the action list the
     * portal shows them, late ones first.
     *
     * @return Collection<int, Dependency>
     */
    public function customerOwedDependencies(): Collection
    {
        if ($this->relationLoaded('dependencies')) {
            return $this->dependencies
                ->filter(fn (Dependency $dependency): bool => $dependency->party === DependencyParty::Customer
                    && $dependency->status->isOutstanding())
                ->sortBy('required_on')
                ->values();
        }

        return $this->dependencies()
            ->where('party', DependencyParty::Customer)
            ->whereIn('status', [DependencyStatus::Pending, DependencyStatus::Requested, DependencyStatus::Escalated])
            ->with(['responsibleStakeholder', 'links.affected'])
            ->orderBy('required_on')
            ->get();
    }

    /**
     * Outstanding dependencies whose required date has passed, whoever owes
     * them — the register's chase list.
     *
     * @return Collection<int, Dependency>
     */
    public function lateDependencies(): Collection
    {
        if ($this->relationLoaded('dependencies')) {
            return $this->dependencies
                ->filter(fn (Dependency $dependency): bool => $dependency->isLate())
                ->sortBy('required_on')
                ->values();
        }

        return $this->dependencies()
            ->whereIn('status', [DependencyStatus::Pending, DependencyStatus::Requested, DependencyStatus::Escalated])
            ->whereDate('required_on', '<', now()->toDateString())
            ->with(['responsibleStakeholder', 'responsibleUser', 'links.affected'])
            ->orderBy('required_on')
            ->get();
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
    public function scopeCreepWorkItems(): HasMany
    {
        return $this->workItems()
            ->whereDoesntHave('link')
            ->whereNull('triage_status');
    }

    /**
     * The aggregate commercial exposure of unresolved scope creep (FA-10): every
     * untriaged unmapped item priced at the current baseline's blended day
     * rates. Items without priceable effort are counted as unpriced —
     * visible risk that carries no number yet, never a made-up one.
     *
     * @return array{count: int, unpriced: int, cost: Money, price: Money}
     */
    public function unbilledRisk(): array
    {
        $rates = $this->currentBaseline()?->blendedDayRates();

        /*
         * A caller that eager-loaded the work items — the portfolio
         * dashboard, constrained to the untriaged unmapped ones — is read
         * in memory; the filter still applies in case the load was wider.
         */
        $items = $this->relationLoaded('workItems')
            ? $this->workItems
                ->filter(fn (WorkItem $item): bool => $item->triage_status === null && $item->link === null)
                ->values()
            : $this->scopeCreepWorkItems()->with('worklogs')->get();

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
     * The change requests still in flight (FA-14): everything raised that the
     * customer has neither approved nor rejected. A request without a price
     * yet is counted rather than guessed at — the queue is real even before
     * the number is.
     *
     * @return array{count: int, unpriced: int, price: Money}
     */
    public function pendingChangeValue(): array
    {
        $pending = $this->changeRequests()
            ->whereNotIn('status', [ChangeRequestStatus::Approved, ChangeRequestStatus::Rejected])
            ->get();

        $prices = $pending
            ->map(fn (ChangeRequest $changeRequest): ?Money => $changeRequest->customer_price)
            ->filter();

        return [
            'count' => $pending->count(),
            'unpriced' => $pending->count() - $prices->count(),
            'price' => $prices->reduce(
                fn (Money $sum, Money $price): Money => $sum->add($price),
                Money::zero(),
            ),
        ];
    }

    /**
     * The derived margin forecast (FA-15), memoized for the request. The rail
     * appears beside every page and the money pages read the same derivation
     * again for their own detail — deriving it twice would mean querying the
     * plan, the recorded weeks and the registers twice to reach identical
     * numbers. The memo only ever serves a derivation at least as complete as
     * the one being asked for.
     *
     * @return array<string, mixed>
     */
    public function marginForecast(bool $withAttribution = true): array
    {
        if ($this->forecast !== null && (! $withAttribution || $this->forecastCarriesAttribution)) {
            return $this->forecast;
        }

        $this->forecastCarriesAttribution = $withAttribution;

        return $this->forecast = app(MarginForecast::class)($this, $withAttribution);
    }

    /**
     * The position rail (FA-14): the live commercial waterfall this
     * engagement is standing on — APPROVED (baseline vN), ACCEPTED (signed),
     * PENDING CR (in flight), UNBILLED RISK (unresolved scope creep) — plus
     * the burn recorded against it and the margin forecast and budget % those
     * derive (FA-15). Every figure carries what it derives from, so the rail
     * can click through to its source rather than assert.
     *
     * Cost, burn and margin are internal (FA-27), and so is the sell-rate
     * derived unbilled-risk price: callers pass whether the viewer may read
     * commercial figures, and for everyone else those blocks are structurally
     * absent while the queue sizes stay visible. Approved, accepted and
     * pending-CR values are contract figures members already read on the
     * baseline and change control pages, so they are not stripped.
     *
     * @return array<string, mixed>
     */
    public function positionSummary(bool $withCommercials): array
    {
        $approved = $this->approvedBaseline();
        $risk = $this->unbilledRisk();
        $change = $this->pendingChangeValue();
        $forecast = $withCommercials ? $this->marginForecast(withAttribution: false) : null;

        return [
            'engagementId' => $this->id,
            'contracted' => $approved?->contract_value->toArray(),
            'baselineVersion' => $approved?->version,
            'accepted' => [
                'count' => $this->deliverables()->where('status', DeliverableStatus::Accepted)->count(),
                'total' => $this->deliverables()->count(),
                'value' => $this->acceptedValue()->toArray(),
            ],
            'pendingChange' => [
                'count' => $change['count'],
                'unpriced' => $change['unpriced'],
                'price' => $change['price']->toArray(),
            ],
            'unbilledRisk' => [
                'count' => $risk['count'],
                'unpriced' => $risk['unpriced'],
                'price' => $withCommercials ? $risk['price']->toArray() : null,
            ],
            'burn' => $forecast === null ? null : [
                'recorded' => $forecast['recordedBurn']->toArray(),
                'costBudget' => $forecast['costBudget']?->toArray(),
                'budgetPercent' => $forecast['budgetPercent'],
                'forecastPercent' => $forecast['forecastPercent'],
                'weeks' => $forecast['weekCount'],
                'unrecordedWeeks' => $forecast['unrecordedWeeks'],
            ],
            'margin' => $forecast === null || ! $forecast['hasBaseline'] ? null : [
                'forecast' => $forecast['margin']->toArray(),
                'percent' => $forecast['marginPercent'],
                'planned' => $forecast['plannedMargin']->toArray(),
                'plannedPercent' => $forecast['plannedMarginPercent'],
                'variance' => $forecast['variance']->toArray(),
                'low' => $forecast['riskBand']['low']->toArray(),
                'lowPercent' => $forecast['riskBand']['lowPercent'],
                'weightedExposure' => $forecast['riskBand']['weightedExposure']->toArray(),
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
     * @return HasMany<Decision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    /**
     * @return HasMany<Risk, $this>
     */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class);
    }

    /**
     * @return HasMany<Dependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(Dependency::class);
    }

    /**
     * Every burn recording ever filed, corrections included — newest week
     * first. Money reads currentBurnWeeks(); this relation is the ledger.
     *
     * @return HasMany<BurnWeek, $this>
     */
    public function burnWeeks(): HasMany
    {
        return $this->hasMany(BurnWeek::class)->orderByDesc('week_start')->orderByDesc('recorded_at');
    }

    /**
     * Every published weekly report, newest week first (FA-26).
     *
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class)->orderByDesc('week_start');
    }

    /**
     * Refuse a write to an archived engagement — archived is read-only and
     * still searchable (FA-3). The message travels under the field the
     * calling form owns, so the refusal lands where the user is looking.
     */
    protected function guardWritable(string $message, string $field): void
    {
        if ($this->status === EngagementStatus::Archived) {
            throw ValidationException::withMessages([$field => $message]);
        }
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
