<?php

namespace App\Http\Controllers;

use App\Actions\Governance\LinkableRecords;
use App\Http\Requests\Risks\StoreRiskRequest;
use App\Http\Requests\Risks\UpdateRiskRequest;
use App\Models\Engagement;
use App\Models\RateCardRole;
use App\Models\RateCardVersion;
use App\Models\Risk;
use App\Models\RiskExposure;
use App\Models\RiskLink;
use App\Models\RiskRevision;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The risk register (FA-19). Exposure is cost-derived, so the euro figures
 * are stripped for viewers without rate card access — the risks themselves
 * stay visible to everyone who works the engagement, which is a different
 * question from what they would cost.
 */
class RiskController extends Controller
{
    public function index(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

        $user = $request->user();
        $withCommercials = $user?->can('viewAny', RateCardVersion::class) ?? false;

        $risks = $engagement->risks()
            ->with(['owner', 'links.threatened', 'exposures.role', 'revisions'])
            ->get()
            ->sortByDesc(fn (Risk $risk): int => $risk->score())
            ->values();

        $exposure = $engagement->riskExposure();

        return Inertia::render('engagements/risks', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'risks' => $risks->map(fn (Risk $risk): array => $this->summarize($risk, $withCommercials))->values(),
            'summary' => [
                'live' => $exposure['count'],
                'escalated' => $exposure['escalated'],
                'exposure' => $withCommercials ? $exposure['exposure']->toArray() : null,
                'weightedExposure' => $withCommercials ? $exposure['weighted']->toArray() : null,
            ],
            'position' => $engagement->positionSummary($withCommercials),
            'can' => [
                'create' => $user?->can('create', [Risk::class, $engagement]) ?? false,
            ],
            'options' => [
                'records' => LinkableRecords::forEngagement($engagement, StoreRiskRequest::LINKABLE),
                'members' => $this->members($engagement),
            ],
        ]);
    }

    /**
     * The full entry: rating and its history, owner, threatened records,
     * mitigation, and the structured exposure behind the euro figure.
     */
    public function show(Request $request, Risk $risk): Response
    {
        Gate::authorize('view', $risk);

        $risk->load(['engagement', 'owner', 'createdBy', 'links.threatened', 'exposures.role', 'revisions.actor', 'rateCardVersion']);

        $user = $request->user();
        $withCommercials = $user?->can('viewAny', RateCardVersion::class) ?? false;
        $engagement = $risk->engagement;

        return Inertia::render('risks/show', [
            'risk' => [
                ...$this->summarize($risk, $withCommercials),
                'description' => $risk->description,
                'mitigation' => $risk->mitigation,
                'createdByName' => $risk->createdBy?->name,
                'rateCardVersion' => $risk->rateCardVersion?->version,
                'closedAt' => $risk->closed_at?->toFormattedDateString(),
            ],
            'exposures' => $withCommercials
                ? $risk->exposures
                    ->map(fn (RiskExposure $exposure): array => [
                        'id' => $exposure->id,
                        'roleId' => $exposure->rate_card_role_id,
                        'roleName' => $exposure->role->name,
                        'days' => (float) $exposure->days,
                        'costPerDay' => $exposure->role->cost_per_day->toArray(),
                        'cost' => $exposure->cost()->toArray(),
                    ])
                    ->values()
                : [],
            'revisions' => $risk->revisions
                ->map(fn (RiskRevision $revision): array => [
                    'id' => $revision->id,
                    'probability' => $revision->probability->value,
                    'probabilityLabel' => $revision->probability->label(),
                    'impact' => $revision->impact->value,
                    'impactLabel' => $revision->impact->label(),
                    'score' => $revision->score,
                    'status' => $revision->status->value,
                    'statusLabel' => $revision->status->label(),
                    'weightedExposure' => $withCommercials ? $revision->weighted_exposure?->toArray() : null,
                    'note' => $revision->note,
                    'actorName' => $revision->actor?->name,
                    'recordedAt' => $revision->created_at->toFormattedDateString(),
                ])
                ->reverse()
                ->values(),
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
            ],
            'options' => [
                'records' => LinkableRecords::forEngagement($engagement, StoreRiskRequest::LINKABLE),
                'members' => $this->members($engagement),
                'roles' => $withCommercials ? $this->roles($risk) : [],
            ],
            'position' => $engagement->positionSummary($withCommercials),
            'can' => [
                'update' => $user instanceof User && $user->can('update', $risk),
                'priceExposure' => ($user instanceof User && $user->can('update', $risk)) && $withCommercials,
            ],
        ]);
    }

    /**
     * Raise a risk. The opening rating is frozen as the first revision, so
     * the history has a baseline to be read against.
     */
    public function store(StoreRiskRequest $request, Engagement $engagement): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $risk = $engagement->registerRisk($this->attributes($validated), $user);
        $risk->syncLinks(LinkableRecords::targets($validated['links'] ?? []), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Risk :title on the register.', [
            'title' => $risk->title,
        ])]);

        return to_route('risks.show', $risk);
    }

    /**
     * Re-rate the risk. A rating or status that actually moved freezes a
     * revision; editing the wording alone does not.
     */
    public function update(UpdateRiskRequest $request, Risk $risk): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $risk->reassess($this->attributes($validated), $user, $validated['note'] ?? null);
        $risk->syncLinks(LinkableRecords::targets($validated['links'] ?? []), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Risk updated — now :probability × :impact.', [
            'probability' => $risk->probability->label(),
            'impact' => $risk->impact->label(),
        ])]);

        return to_route('risks.show', $risk);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Risk $risk, bool $withCommercials): array
    {
        return [
            'id' => $risk->id,
            'title' => $risk->title,
            'probability' => $risk->probability->value,
            'probabilityLabel' => $risk->probability->label(),
            'impact' => $risk->impact->value,
            'impactLabel' => $risk->impact->label(),
            'score' => $risk->score(),
            'status' => $risk->status->value,
            'statusLabel' => $risk->status->label(),
            'ownerName' => $risk->owner?->name,
            'ownerId' => $risk->owner_id,
            'visibility' => $risk->visibility->value,
            'visibilityLabel' => $risk->visibility->label(),
            'escalated' => $risk->isEscalated(),
            'worsening' => $risk->isWorsening(),
            'exposure' => $withCommercials ? $risk->exposure()->toArray() : null,
            'weightedExposure' => $withCommercials ? $risk->weightedExposure()->toArray() : null,
            'exposureLineCount' => $risk->exposures->count(),
            'links' => $risk->links->map(fn (RiskLink $link): array => $link->describe())->values(),
            'raisedAt' => $risk->created_at?->toFormattedDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'probability' => $validated['probability'],
            'impact' => $validated['impact'],
            'status' => $validated['status'],
            'owner_id' => $validated['owner_id'] ?? null,
            'mitigation' => $validated['mitigation'] ?? null,
            'visibility' => $validated['visibility'],
        ];
    }

    /**
     * @return Collection<int, array{value: string, label: string}>
     */
    private function members(Engagement $engagement): Collection
    {
        return User::query()
            ->where('organization_id', $engagement->organization_id)
            ->orderBy('name')
            ->get()
            ->map(fn (User $member): array => ['value' => $member->id, 'label' => $member->name])
            ->values();
    }

    /**
     * The roles exposure can be priced against: the ones on the rate card
     * version pinned to this risk.
     *
     * @return Collection<int, array{value: string, label: string, costPerDay: array{amount: int, currency: string, formatted: string}}>
     */
    private function roles(Risk $risk): Collection
    {
        return RateCardRole::query()
            ->where('rate_card_version_id', $risk->rate_card_version_id)
            ->orderBy('position')
            ->get()
            ->map(fn (RateCardRole $role): array => [
                'value' => $role->id,
                'label' => $role->name,
                'costPerDay' => $role->cost_per_day->toArray(),
            ])
            ->values();
    }
}
