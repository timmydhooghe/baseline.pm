<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\WeeklyReportDraft;
use App\Http\Requests\Reports\PublishReportRequest;
use App\Models\Baseline;
use App\Models\BurnWeek;
use App\Models\ChangeRequest;
use App\Models\Decision;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\RateCardVersion;
use App\Models\Report;
use App\Models\Risk;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly evidence-based reporting (FA-26). Drafts are derived from the
 * ledgers every time they are read — never stored, so never stale — and
 * publishing freezes twin snapshots: the internal one keeps the commercial
 * position, the customer one is built without cost or margin. A published
 * report always renders from its frozen snapshot; what was sent is what
 * stays shown.
 *
 * The internal variant's commercials block is served only to the roles that
 * may read the rate card — for everyone else it is structurally absent from
 * the props, exactly like the position rail (FA-27).
 */
class ReportController extends Controller
{
    public function index(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);
        Gate::authorize('viewAny', Report::class);

        $user = $request->user();
        $due = array_reverse($engagement->dueReportWeeks());

        return Inertia::render('engagements/reports', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'due' => array_map(fn (CarbonImmutable $week): array => [
                'weekStart' => $week->toDateString(),
                'weekLabel' => BurnWeek::labelFor($week),
            ], $due),
            'published' => $engagement->reports()
                ->with('publishedBy')
                ->get()
                ->map(fn (Report $report): array => [
                    'id' => $report->id,
                    'weekStart' => $report->week_start->toDateString(),
                    'weekLabel' => $report->label(),
                    'publishedAt' => $report->published_at->toFormattedDayDateString(),
                    'publishedByName' => $report->publishedBy?->name,
                ])
                ->values(),
            'position' => $engagement->positionSummary(
                $user?->can('viewAny', RateCardVersion::class) ?? false,
            ),
            'can' => [
                'publish' => $user?->can('create', [Report::class, $engagement]) ?? false,
            ],
        ]);
    }

    /**
     * A week's draft, derived from evidence as it stands right now. A week
     * that is already published has no draft — the frozen report is the only
     * story that week tells.
     */
    public function draft(Request $request, Engagement $engagement, string $week, WeeklyReportDraft $draft): Response|RedirectResponse
    {
        Gate::authorize('view', $engagement);
        Gate::authorize('viewAny', Report::class);

        if (strtotime($week) === false) {
            abort(404);
        }

        $weekStart = BurnWeek::startOfWeekFor($week);

        $published = $engagement->reports()
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        if ($published !== null) {
            return to_route('reports.show', $published);
        }

        $user = $request->user();
        $withCommercials = $user?->can('viewAny', RateCardVersion::class) ?? false;

        return Inertia::render('engagements/report', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'report' => [
                'published' => false,
                'weekStart' => $weekStart->toDateString(),
                'weekLabel' => BurnWeek::labelFor($weekStart),
                'publishedAt' => null,
                'publishedByName' => null,
            ],
            'variants' => [
                'internal' => $this->forViewer($draft($engagement, $weekStart, internal: true), $withCommercials),
                'customer' => $draft($engagement, $weekStart, internal: false),
            ],
            'position' => $engagement->positionSummary($withCommercials),
            'can' => [
                'publish' => $user?->can('create', [Report::class, $engagement]) ?? false,
            ],
        ]);
    }

    /**
     * A published report, rendered from its frozen snapshots only — the
     * ledgers may have moved on, the report has not.
     */
    public function show(Request $request, Report $report): Response
    {
        Gate::authorize('view', $report);

        $engagement = $report->engagement;
        $user = $request->user();
        $withCommercials = $user?->can('viewAny', RateCardVersion::class) ?? false;

        return Inertia::render('engagements/report', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'report' => [
                'published' => true,
                'weekStart' => $report->week_start->toDateString(),
                'weekLabel' => $report->label(),
                'publishedAt' => $report->published_at->toFormattedDayDateString(),
                'publishedByName' => $report->publishedBy?->name,
            ],
            'variants' => [
                'internal' => $this->forViewer($report->reviewSnapshot->payload ?? [], $withCommercials),
                'customer' => $report->customerSnapshot->payload ?? [],
            ],
            'position' => $engagement->positionSummary($withCommercials),
            'can' => [
                'publish' => false,
            ],
        ]);
    }

    /**
     * Publish the week: freeze the twin snapshots and send the stakeholders
     * their links. Everything after the week itself is derived server-side.
     */
    public function store(PublishReportRequest $request, Engagement $engagement): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $report = $engagement->publishWeeklyReport($validated['week_start'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report for :week published and sent to the customer\'s stakeholders.', [
            'week' => $report->label(),
        ])]);

        return to_route('reports.show', $report);
    }

    /**
     * The internal payload as this viewer may read it: with the commercials
     * block only when they may read the rate card, structurally absent
     * otherwise. The frozen snapshot keeps the full record either way.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function forViewer(array $payload, bool $withCommercials): array
    {
        if (! $withCommercials) {
            unset($payload['commercials']);
        }

        return $this->withRecordLinks($payload);
    }

    /**
     * Decorate every line's record chip with where it lives today. Links are
     * resolved at render time, never frozen into the snapshot — a record's
     * address may move, the report's content may not.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withRecordLinks(array $payload): array
    {
        foreach (['moved', 'changed', 'owed'] as $section) {
            if (! is_array($payload[$section] ?? null)) {
                continue;
            }

            foreach ($payload[$section] as $index => $line) {
                if (is_array($line) && is_array($line['record'] ?? null)) {
                    $payload[$section][$index]['record']['href'] = $this->recordHref(
                        $line['record'],
                        is_array($payload['engagement'] ?? null) ? ($payload['engagement']['id'] ?? null) : null,
                    );
                }
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $chip
     */
    private function recordHref(array $chip, ?string $engagementId): ?string
    {
        $id = $chip['id'] ?? null;

        if (! is_string($id)) {
            return null;
        }

        return match ($chip['type'] ?? null) {
            (new Deliverable)->getMorphClass() => route('deliverables.show', $id),
            (new ChangeRequest)->getMorphClass() => route('change-requests.show', $id),
            (new Risk)->getMorphClass() => route('risks.show', $id),
            (new Dependency)->getMorphClass() => route('dependencies.show', $id),
            (new Decision)->getMorphClass() => route('decisions.show', $id),
            (new Baseline)->getMorphClass() => $engagementId === null ? null : route('engagements.baseline.show', $engagementId),
            default => null,
        };
    }
}
