<?php

namespace App\Actions\Money;

use App\Enums\BurnSource;
use App\Enums\EstimateUnit;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\BurnEntry;
use App\Models\BurnWeek;
use App\Models\Deliverable;
use App\Models\Engagement;
use App\Models\RateCardRole;
use App\Models\User;
use App\Models\WorkItemWorklog;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a week of burn looks like before anybody types anything (FA-16). The
 * source hierarchy runs top down:
 *
 * 1. Time tracking prefills the people who logged hours that week.
 * 2. Profiles with no logged time get a suggestion derived from deliverable
 *    progress against their planned role mix.
 * 3. Everything stays editable, and a profile can always be added by hand.
 *
 * A suggestion is a starting point, never a record: nothing here writes, and
 * every figure is re-derivable from the worklogs, the plan and the progress
 * it was built from. Correcting a week that is already recorded reads the
 * recorded lines back instead — a correction starts from what was recorded,
 * not from what was once suggested.
 */
class WeeklyBurnSuggestion
{
    /**
     * @return array{
     *     weekStart: string,
     *     weekLabel: string,
     *     recorded: bool,
     *     recordedAt: string|null,
     *     recordedByName: string|null,
     *     weightedProgress: float|null,
     *     loggedHours: float,
     *     lines: list<array<string, mixed>>,
     *     roles: list<array{value: string, label: string, costPerDay: array{amount: int, currency: string, formatted: string}}>,
     * }
     */
    public function __invoke(Engagement $engagement, DateTimeInterface|string $week): array
    {
        $weekStart = BurnWeek::startOfWeekFor($week);
        $baseline = $engagement->approvedBaseline();
        $pinned = $baseline?->rateCardVersion;
        $version = $pinned ?? $engagement->organization->currentRateCardVersion();
        /** @var Collection<int, RateCardRole> $roles */
        $roles = $version === null ? new Collection : $version->roles;

        $existing = $engagement->burnWeeks()
            ->whereNull('superseded_at')
            ->whereDate('week_start', $weekStart->toDateString())
            ->with(['entries.role', 'recordedBy'])
            ->first();

        $progress = $this->weightedProgress($engagement);
        $worklogs = $this->loggedDaysByAuthor($engagement, $weekStart);

        return [
            'weekStart' => $weekStart->toDateString(),
            'weekLabel' => BurnWeek::labelFor($weekStart),
            'recorded' => $existing !== null,
            'recordedAt' => $existing?->recorded_at->toFormattedDayDateString(),
            'recordedByName' => $existing?->recordedBy?->name,
            'weightedProgress' => $progress,
            'loggedHours' => round(array_sum($worklogs) * EstimateUnit::HOURS_PER_DAY, 1),
            'lines' => $existing !== null
                ? $this->recordedLines($existing)
                : $this->suggestedLines($engagement, $baseline, $roles, $worklogs, $weekStart, $progress),
            'roles' => array_values($roles
                ->map(fn (RateCardRole $role): array => [
                    'value' => $role->id,
                    'label' => $role->name,
                    'costPerDay' => $role->cost_per_day->toArray(),
                ])
                ->all()),
        ];
    }

    /**
     * A week already on record reads back exactly as it was recorded — the
     * correction starts from the frozen lines, so a manager fixing one number
     * does not silently restate the rest of the week from a fresh suggestion.
     *
     * @return list<array<string, mixed>>
     */
    private function recordedLines(BurnWeek $week): array
    {
        return array_values($week->entries
            ->map(fn (BurnEntry $entry): array => [
                'roleId' => $entry->rate_card_role_id,
                'roleName' => $entry->role_name,
                'personName' => $entry->person_name,
                'userId' => $entry->user_id,
                'days' => (float) $entry->days,
                'source' => $entry->source->value,
                'sourceLabel' => $entry->source->label(),
                'costPerDay' => $entry->cost_per_day->toArray(),
                'cost' => $entry->cost->toArray(),
                'basis' => __('Recorded :source.', ['source' => mb_lcfirst($entry->source->label())]),
            ])
            ->all());
    }

    /**
     * The prefilled week: everybody who logged time, then every planned
     * profile that logged none.
     *
     * @param  Collection<int, RateCardRole>  $roles
     * @param  array<string, float>  $worklogs
     * @return list<array<string, mixed>>
     */
    private function suggestedLines(
        Engagement $engagement,
        ?Baseline $baseline,
        Collection $roles,
        array $worklogs,
        CarbonImmutable $weekStart,
        ?float $progress,
    ): array {
        $rolesByName = $roles->keyBy('name');
        $history = $this->history($engagement, $weekStart);
        $members = User::query()
            ->where('organization_id', $engagement->organization_id)
            ->get()
            ->keyBy('name');

        $lines = [];
        $covered = [];

        foreach ($worklogs as $author => $days) {
            /*
             * The profile a person was last recorded against on this
             * engagement. Picking it once is enough — every later week
             * prefills from the ledger rather than asking again.
             */
            $roleName = $history['personRoles'][$author] ?? null;
            $role = $roleName === null ? null : $rolesByName->get($roleName);

            if ($role instanceof RateCardRole) {
                $covered[$role->name] = true;
            }

            $lines[] = $this->line(
                role: $role,
                personName: $author,
                userId: $members->get($author)?->id,
                days: $days,
                source: BurnSource::Worklog,
                basis: __(':hours h logged that week.', ['hours' => $this->number($days * EstimateUnit::HOURS_PER_DAY)]),
            );
        }

        if ($baseline === null) {
            return $lines;
        }

        /*
         * A profile whose people are already accounted for by logged time
         * needs no estimate. A person nobody has booked against a profile yet
         * covers nothing — their profile still gets its suggestion, and the
         * manager resolves the overlap in review, which is the one place a
         * guess about who did what belongs.
         */
        foreach ($this->plannedDaysByRoleName($baseline) as $roleName => $planned) {
            if (isset($covered[$roleName])) {
                continue;
            }

            $role = $rolesByName->get($roleName);

            if (! $role instanceof RateCardRole) {
                continue;
            }

            /*
             * The progress-derived suggestion: how much of the profile's
             * planned effort the delivered progress implies should be spent
             * by now, minus what earlier weeks already recorded. Never
             * negative — a profile that ran ahead of progress is not owed
             * days back.
             */
            $recorded = $history['roleDays'][$roleName] ?? 0.0;
            $expected = $planned * ($progress ?? 0.0);
            $suggested = round(max(0.0, $expected - $recorded), 2);

            $lines[] = $this->line(
                role: $role,
                personName: null,
                userId: null,
                days: $suggested,
                source: BurnSource::Progress,
                basis: $progress === null
                    ? __('No progress recorded yet — enter the days by hand.')
                    : __(':progress% of :planned planned days, :recorded already recorded.', [
                        'progress' => $this->number($progress * 100),
                        'planned' => $this->number($planned),
                        'recorded' => $this->number($recorded),
                    ]),
            );
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(
        ?RateCardRole $role,
        ?string $personName,
        ?string $userId,
        float $days,
        BurnSource $source,
        string $basis,
    ): array {
        $rate = $role === null ? Money::zero() : $role->cost_per_day;
        $cost = Money::fromCents((int) round($days * $rate->amount));

        return [
            'roleId' => $role?->id,
            'roleName' => $role?->name,
            'personName' => $personName,
            'userId' => $userId,
            'days' => $days,
            'source' => $source->value,
            'sourceLabel' => $source->label(),
            'costPerDay' => $role === null ? null : $rate->toArray(),
            'cost' => $role === null ? null : $cost->toArray(),
            'basis' => $basis,
        ];
    }

    /**
     * Days logged that week per person, from the connected tool's worklogs
     * (FA-7) and from manual entries alike. Seconds are the storage unit;
     * days are what burn is recorded in.
     *
     * @return array<string, float>
     */
    private function loggedDaysByAuthor(Engagement $engagement, CarbonImmutable $weekStart): array
    {
        $seconds = WorkItemWorklog::query()
            ->whereHas('workItem', fn ($query) => $query->where('engagement_id', $engagement->id))
            ->whereBetween('logged_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->selectRaw('author_name, sum(seconds) as total')
            ->groupBy('author_name')
            ->orderBy('author_name')
            ->pluck('total', 'author_name');

        return $seconds
            ->map(fn (int|string $total): float => round((int) $total / 3600 / EstimateUnit::HOURS_PER_DAY, 2))
            ->all();
    }

    /**
     * What the ledger already knows, read from the weeks before this one:
     * the days recorded per profile, and the profile each person was last
     * recorded against.
     *
     * @return array{roleDays: array<string, float>, personRoles: array<string, string>}
     */
    private function history(Engagement $engagement, CarbonImmutable $weekStart): array
    {
        $weeks = $engagement->burnWeeks()
            ->whereNull('superseded_at')
            ->whereDate('week_start', '<', $weekStart->toDateString())
            ->with('entries')
            ->orderBy('week_start')
            ->get();

        $roleDays = [];
        $personRoles = [];

        foreach ($weeks as $week) {
            foreach ($week->entries as $entry) {
                $roleDays[$entry->role_name] = ($roleDays[$entry->role_name] ?? 0.0) + (float) $entry->days;

                if ($entry->person_name !== null) {
                    $personRoles[$entry->person_name] = $entry->role_name;
                }
            }
        }

        return ['roleDays' => $roleDays, 'personRoles' => $personRoles];
    }

    /**
     * The planned role mix, in days per profile — delivery management
     * included, because somebody burns those days too.
     *
     * @return array<string, float>
     */
    private function plannedDaysByRoleName(Baseline $baseline): array
    {
        $planned = [];

        foreach ($baseline->allocations()->with('role')->get() as $allocation) {
            /** @var BaselineAllocation $allocation */
            $planned[$allocation->role->name] = ($planned[$allocation->role->name] ?? 0.0) + (float) $allocation->days;
        }

        return $planned;
    }

    /**
     * How far the engagement has actually got, weighted by what each
     * deliverable is worth — a 5% deliverable at 100% is not the same news
     * as the flagship one at 100%. Null when there is nothing to measure
     * against: a suggestion built on no progress at all would be invented.
     */
    private function weightedProgress(Engagement $engagement): ?float
    {
        $deliverables = $engagement->deliverables()->with('baselineItem')->get();

        if ($deliverables->isEmpty()) {
            return null;
        }

        $valueOf = function (Deliverable $deliverable): int {
            $value = $deliverable->baselineItem->value;

            return $value === null ? 0 : $value->amount;
        };

        $weight = $deliverables->sum($valueOf);

        /*
         * Nothing carries a commercial value yet — an unpriced structure, or
         * a change-request deliverable minted without one. A straight average
         * is the honest reading: every deliverable counts the same because
         * nothing says otherwise.
         */
        if ($weight <= 0) {
            return round($deliverables->avg(fn (Deliverable $deliverable): int => $deliverable->progress) / 100, 4);
        }

        $weighted = $deliverables->sum(
            fn (Deliverable $deliverable): float => $valueOf($deliverable) * $deliverable->progress,
        );

        return round($weighted / $weight / 100, 4);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
