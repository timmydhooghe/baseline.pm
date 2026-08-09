<?php

namespace App\Http\Controllers;

use App\Actions\Governance\LinkableRecords;
use App\Enums\DependencyEventType;
use App\Http\Requests\Dependencies\StoreDependencyRequest;
use App\Http\Requests\Dependencies\UpdateDependencyRequest;
use App\Models\Dependency;
use App\Models\DependencyEvent;
use App\Models\DependencyLink;
use App\Models\Engagement;
use App\Models\RateCardVersion;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dependency register (FA-20). Every entry names the person who owes it,
 * carries the delay it has accrued day for day, and attributes that delay to
 * the side that owes it — which is what a milestone slip is defended with.
 */
class DependencyController extends Controller
{
    public function index(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

        $user = $request->user();

        $dependencies = $engagement->dependencies()
            ->with(['responsibleStakeholder', 'responsibleUser', 'links.affected', 'events'])
            ->orderBy('required_on')
            ->get();

        $outstanding = $dependencies->filter(fn (Dependency $dependency): bool => $dependency->status->isOutstanding());

        return Inertia::render('engagements/dependencies', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'dependencies' => $dependencies->map($this->summarize(...))->values(),
            'summary' => [
                'outstanding' => $outstanding->count(),
                'late' => $outstanding->filter(fn (Dependency $dependency): bool => $dependency->isLate())->count(),
                'customerOwed' => $outstanding->filter(fn (Dependency $dependency): bool => $dependency->party->isCustomer())->count(),
                'worstDelayDays' => (int) $outstanding->max(fn (Dependency $dependency): int => $dependency->delayDays()),
            ],
            'options' => $this->options($engagement),
            'position' => $engagement->positionSummary($user?->can('viewAny', RateCardVersion::class) ?? false),
            'can' => [
                'create' => $user?->can('create', [Dependency::class, $engagement]) ?? false,
            ],
        ]);
    }

    /**
     * The full entry: who owes it, what it blocks and by how many days, and
     * the evidence trail of everything that was done to chase it.
     */
    public function show(Request $request, Dependency $dependency): Response
    {
        Gate::authorize('view', $dependency);

        $dependency->load([
            'engagement', 'responsibleStakeholder', 'responsibleUser', 'createdBy',
            'links.affected', 'events.actor',
        ]);

        $user = $request->user();
        $engagement = $dependency->engagement;

        return Inertia::render('dependencies/show', [
            'dependency' => [
                ...$this->summarize($dependency),
                'description' => $dependency->description,
                'createdByName' => $dependency->createdBy?->name,
                'escalatedAt' => $dependency->escalated_at?->toFormattedDateString(),
            ],
            'impact' => $dependency->projectedImpact(),
            'events' => $dependency->events
                ->map(fn (DependencyEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'typeLabel' => $event->type->label(),
                    'channel' => $event->channel,
                    'note' => $event->note,
                    'evidenceUrl' => $event->evidence_url,
                    'actorName' => $event->actor?->name,
                    'occurredAt' => $event->occurred_at->toFormattedDateString(),
                ])
                ->reverse()
                ->values(),
            'eventTypes' => collect(DependencyEventType::cases())
                ->map(fn (DependencyEventType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])
                ->values(),
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
            ],
            'options' => $this->options($engagement),
            'position' => $engagement->positionSummary($user?->can('viewAny', RateCardVersion::class) ?? false),
            'can' => [
                'update' => ($user instanceof User && $user->can('update', $dependency))
                    && $dependency->status->isOutstanding(),
            ],
        ]);
    }

    public function store(StoreDependencyRequest $request, Engagement $engagement): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $dependency = $engagement->registerDependency($this->attributes($validated), $user);
        $dependency->syncLinks(LinkableRecords::targets($validated['links'] ?? []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dependency :title registered, owed by :who.', [
            'title' => $dependency->title,
            'who' => $dependency->responsibleName() ?? __('nobody yet'),
        ])]);

        return to_route('dependencies.show', $dependency);
    }

    public function update(UpdateDependencyRequest $request, Dependency $dependency): RedirectResponse
    {
        $validated = $request->validated();

        $dependency->update($this->attributes($validated));
        $dependency->syncLinks(LinkableRecords::targets($validated['links'] ?? []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dependency updated.')]);

        return to_route('dependencies.show', $dependency);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Dependency $dependency): array
    {
        return [
            'id' => $dependency->id,
            'title' => $dependency->title,
            'party' => $dependency->party->value,
            'partyLabel' => $dependency->party->label(),
            'status' => $dependency->status->value,
            'statusLabel' => $dependency->status->label(),
            'responsibleName' => $dependency->responsibleName(),
            'responsibleStakeholderId' => $dependency->responsible_stakeholder_id,
            'responsibleUserId' => $dependency->responsible_user_id,
            'requiredOn' => $dependency->required_on->toFormattedDateString(),
            'requiredOnDate' => $dependency->required_on->toDateString(),
            'settledOn' => $dependency->settled_on?->toFormattedDateString(),
            'delayDays' => $dependency->delayDays(),
            'late' => $dependency->isLate(),
            'attribution' => $dependency->attribution()->value,
            'attributionLabel' => $dependency->attribution()->label(),
            'visibility' => $dependency->visibility->value,
            'visibilityLabel' => $dependency->visibility->label(),
            'links' => $dependency->links->map(fn (DependencyLink $link): array => $link->describe())->values(),
            'eventCount' => $dependency->events->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Engagement $engagement): array
    {
        return [
            'records' => LinkableRecords::forEngagement($engagement, StoreDependencyRequest::LINKABLE),
            'stakeholders' => Stakeholder::query()
                ->where('customer_id', $engagement->customer_id)
                ->orderBy('name')
                ->get()
                ->map(fn (Stakeholder $stakeholder): array => [
                    'value' => $stakeholder->id,
                    'label' => "{$stakeholder->name} · {$stakeholder->role->label()}",
                ])
                ->values(),
            'members' => $this->members($engagement),
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
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $isCustomer = $validated['party'] === 'customer';

        return [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'party' => $validated['party'],
            'responsible_stakeholder_id' => $isCustomer ? ($validated['responsible_stakeholder_id'] ?? null) : null,
            'responsible_user_id' => $isCustomer ? null : ($validated['responsible_user_id'] ?? null),
            'required_on' => $validated['required_on'],
            'visibility' => $validated['visibility'],
        ];
    }
}
