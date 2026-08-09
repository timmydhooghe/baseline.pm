<?php

namespace App\Http\Controllers;

use App\Actions\Governance\LinkableRecords;
use App\Enums\DecisionStatus;
use App\Enums\RecordVisibility;
use App\Http\Requests\Decisions\StoreDecisionRequest;
use App\Http\Requests\Decisions\UpdateDecisionRequest;
use App\Models\AuditLog;
use App\Models\Decision;
use App\Models\DecisionLink;
use App\Models\Engagement;
use App\Models\RateCardVersion;
use App\Models\Stakeholder;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The decision ledger (FA-18). Records answer "why is it like this?", so the
 * index is searchable and every entry carries the context, the alternatives
 * and the records it touched. Drafts stay editable; confirmed records are
 * superseded rather than rewritten.
 */
class DecisionController extends Controller
{
    public function index(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

        $search = trim($request->string('q')->value());
        $user = $request->user();

        $decisions = $engagement->decisions()
            ->with(['decidedBy', 'links.linked', 'supersedes', 'supersededBy'])
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $matches) => $matches
                    ->whereLike('title', "%{$search}%")
                    ->orWhereLike('context', "%{$search}%")
                    ->orWhereLike('decision', "%{$search}%")
                    ->orWhereLike('impact_scope', "%{$search}%"),
            ))
            ->orderByDesc('created_at')
            ->get()
            /*
             * Newest decision first, with drafts — which have no date yet —
             * on top, because they are the ones still waiting on somebody.
             */
            ->sortByDesc(fn (Decision $decision): int => $decision->decided_on?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        return Inertia::render('engagements/decisions', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'decisions' => $decisions->map($this->summarize(...))->values(),
            'counts' => [
                'total' => $decisions->count(),
                'drafts' => $decisions->where('status', DecisionStatus::Draft)->count(),
                'shared' => $decisions->where('visibility', RecordVisibility::Shared)->count(),
                'awaitingAcknowledgement' => $decisions
                    ->filter(fn (Decision $decision): bool => $decision->visibility->isShared()
                        && $decision->status->isConfirmed()
                        && $decision->acknowledged_at === null)
                    ->count(),
            ],
            'filters' => ['q' => $search],
            'options' => [
                'records' => LinkableRecords::forEngagement($engagement, StoreDecisionRequest::LINKABLE),
            ],
            'position' => $engagement->positionSummary($user?->can('viewAny', RateCardVersion::class) ?? false),
            'can' => [
                'create' => $user?->can('create', [Decision::class, $engagement]) ?? false,
            ],
        ]);
    }

    /**
     * The full record: context, alternatives, participants, impact, evidence
     * links, the records it touched, its place in the supersedes-chain and
     * the customer's acknowledgment.
     */
    public function show(Request $request, Decision $decision): Response
    {
        Gate::authorize('view', $decision);

        $decision->load([
            'engagement', 'decidedBy', 'createdBy', 'acknowledgedBy',
            'links.linked', 'supersedes.decidedBy', 'supersededBy',
        ]);

        $user = $request->user();
        $engagement = $decision->engagement;

        return Inertia::render('decisions/show', [
            'decision' => [
                ...$this->summarize($decision),
                'context' => $decision->context,
                'alternatives' => $decision->alternatives ?? [],
                'participants' => $decision->participants ?? [],
                'evidence' => $decision->evidence ?? [],
                'impact' => [
                    'scope' => $decision->impact_scope,
                    'budget' => $decision->impact_budget?->toArray(),
                    'timelineDays' => $decision->impact_timeline_days,
                ],
                'transcriptExcerpt' => $decision->transcript_excerpt,
                'createdByName' => $decision->createdBy?->name,
                'acknowledgementComment' => $decision->acknowledgement_comment,
            ],
            'chain' => collect($decision->supersedesChain())
                ->map(fn (Decision $ancestor): array => [
                    'id' => $ancestor->id,
                    'title' => $ancestor->title,
                    'decidedOn' => $ancestor->decided_on?->toFormattedDateString(),
                    'statusLabel' => $ancestor->status->label(),
                ])
                ->values(),
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
            ],
            'acknowledgementLinks' => $this->acknowledgementLinks($decision),
            'options' => $this->options($engagement, $decision),
            'position' => $engagement->positionSummary($user?->can('viewAny', RateCardVersion::class) ?? false),
            'can' => [
                'update' => ($user instanceof User && $user->can('update', $decision))
                    && $decision->status->acceptsEdits(),
                'confirm' => ($user instanceof User && $user->can('confirm', $decision))
                    && $decision->status->acceptsEdits(),
                'delete' => $user instanceof User && $user->can('delete', $decision),
            ],
        ]);
    }

    /**
     * Draft a decision by hand. Drafts are proposals — nothing is on the
     * ledger until it is confirmed.
     */
    public function store(StoreDecisionRequest $request, Engagement $engagement): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $decision = $engagement->recordDecision($this->attributes($validated), $user);
        $decision->syncLinks($this->linkTargets($validated), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision draft :title recorded.', [
            'title' => $decision->title,
        ])]);

        return to_route('decisions.show', $decision);
    }

    /**
     * Edit a draft. The model refuses this once the record is confirmed.
     */
    public function update(UpdateDecisionRequest $request, Decision $decision): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $decision->fill($this->attributes($validated, $decision));
        $changes = collect($decision->getDirty())->except('updated_at')->keys()->all();
        $decision->save();

        if ($changes !== []) {
            AuditLog::record('decision.updated', $decision, [
                'decision' => $decision->title,
                'changed' => $changes,
                'updated_by' => $user?->name,
            ]);
        }

        $decision->syncLinks($this->linkTargets($validated), $user instanceof User ? $user : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision draft updated.')]);

        return to_route('decisions.show', $decision);
    }

    /**
     * Discard a draft that never became a decision.
     */
    public function destroy(Request $request, Decision $decision): RedirectResponse
    {
        Gate::authorize('delete', $decision);

        $engagement = $decision->engagement;

        AuditLog::record('decision.draft_discarded', $decision, [
            'decision' => $decision->title,
            'discarded_by' => $request->user()?->name,
        ]);

        $decision->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision draft discarded.')]);

        return to_route('engagements.decisions.index', $engagement);
    }

    /**
     * The shape every decision list and detail view shares.
     *
     * @return array<string, mixed>
     */
    private function summarize(Decision $decision): array
    {
        return [
            'id' => $decision->id,
            'title' => $decision->title,
            'decision' => $decision->decision,
            'status' => $decision->status->value,
            'statusLabel' => $decision->status->label(),
            'source' => $decision->source->value,
            'sourceLabel' => $decision->source->label(),
            'visibility' => $decision->visibility->value,
            'visibilityLabel' => $decision->visibility->label(),
            'decidedOn' => $decision->decided_on?->toFormattedDateString(),
            'decidedOnDate' => $decision->decided_on?->toDateString(),
            'decidedById' => $decision->decided_by,
            'decidedByName' => $decision->decidedBy?->name,
            'impactScope' => $decision->impact_scope,
            'impactTimelineDays' => $decision->impact_timeline_days,
            'participantCount' => count($decision->participants ?? []),
            'links' => $decision->links->map(fn (DecisionLink $link): array => $link->describe())->values(),
            'supersedesId' => $decision->supersedes_id,
            'supersedesTitle' => $decision->supersedes?->title,
            'supersededById' => $decision->supersededBy?->id,
            'supersededByTitle' => $decision->supersededBy?->title,
            'acknowledgedAt' => $decision->acknowledged_at?->toFormattedDateString(),
            'acknowledgedByName' => $decision->acknowledged_by_name,
            'recordedAt' => $decision->created_at?->toFormattedDateString(),
        ];
    }

    /**
     * The personally signed links a shared decision is acknowledged through
     * (FA-18). One per stakeholder of the engagement's customer: the
     * signature covers the stakeholder and the frozen snapshot, so a link
     * can neither be reassigned nor made to show a different record.
     *
     * @return list<array{stakeholderName: string, url: string}>
     */
    private function acknowledgementLinks(Decision $decision): array
    {
        if (! $decision->visibility->isShared() || $decision->customer_snapshot_id === null) {
            return [];
        }

        return array_values($decision->engagement->customer->stakeholders
            ->map(fn (Stakeholder $stakeholder): array => [
                'stakeholderName' => $stakeholder->name,
                'url' => URL::signedRoute('portal.decisions.show', [
                    'decision' => $decision->id,
                    'stakeholder' => $stakeholder->id,
                    'snapshot' => $decision->customer_snapshot_id,
                ]),
            ])
            ->all());
    }

    /**
     * Everything a draft form needs to offer as a chip or a choice: the
     * engagement's own records, its members, and the confirmed decisions
     * this one could supersede.
     *
     * @return array<string, mixed>
     */
    private function options(Engagement $engagement, Decision $decision): array
    {
        return [
            'records' => LinkableRecords::forEngagement($engagement, StoreDecisionRequest::LINKABLE),
            'members' => User::query()
                ->where('organization_id', $engagement->organization_id)
                ->orderBy('name')
                ->get()
                ->map(fn (User $member): array => ['value' => $member->id, 'label' => $member->name])
                ->values(),
            'supersedable' => $engagement->decisions()
                ->where('status', DecisionStatus::Confirmed)
                ->whereKeyNot($decision->id)
                ->whereDoesntHave('supersededBy', fn (Builder $claimant) => $claimant->whereKeyNot($decision->id))
                ->orderByDesc('decided_on')
                ->get()
                ->map(fn (Decision $candidate): array => [
                    'value' => $candidate->id,
                    'label' => $candidate->title,
                ])
                ->values(),
        ];
    }

    /**
     * The model attributes behind a validated ledger form. Budget impact
     * arrives in euros and is stored as cents — the ledger holds money the
     * same way the rest of the product does.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated, ?Decision $decision = null): array
    {
        $budget = $validated['impact_budget'] ?? null;

        return [
            'title' => $validated['title'],
            'context' => $validated['context'],
            'decision' => $validated['decision'] ?? null,
            /*
             * A form that carries none of these rows is saying "unchanged",
             * not "delete them": an empty list arrives as the explicit
             * `*_cleared` flag instead. Without the distinction, adding the
             * outcome to a transcript-proposed draft would quietly erase the
             * participants it extracted.
             */
            'alternatives' => $this->structured($validated, $decision, 'alternatives'),
            'participants' => $this->structured($validated, $decision, 'participants'),
            'evidence' => $this->structured($validated, $decision, 'evidence'),
            'impact_scope' => $validated['impact_scope'] ?? null,
            'impact_budget' => $budget === null ? null : Money::fromCents((int) round((float) $budget * 100)),
            'impact_timeline_days' => $validated['impact_timeline_days'] ?? null,
            'visibility' => $validated['visibility'],
            'decided_on' => $validated['decided_on'] ?? null,
            'decided_by' => $validated['decided_by'] ?? null,
            'supersedes_id' => $validated['supersedes_id'] ?? null,
        ];
    }

    /**
     * One structured list on the record: the submitted rows when the form
     * carried them, an empty list when it says so explicitly, and what is
     * already stored when the form never mentioned them.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private function structured(array $validated, ?Decision $decision, string $key): array
    {
        if (array_key_exists($key, $validated) && is_array($validated[$key])) {
            return array_values($validated[$key]);
        }

        if (($validated["{$key}_cleared"] ?? false) === true) {
            return [];
        }

        return array_values($decision?->getAttribute($key) ?? []);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{type: string, id: string}>
     */
    private function linkTargets(array $validated): array
    {
        return LinkableRecords::targets($validated['links'] ?? []);
    }
}
