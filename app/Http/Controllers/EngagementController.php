<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestStatus;
use App\Enums\DecisionStatus;
use App\Enums\DeliverableStatus;
use App\Enums\DependencyStatus;
use App\Enums\EngagementStatus;
use App\Enums\RecordVisibility;
use App\Enums\RiskStatus;
use App\Http\Requests\Engagements\StoreEngagementRequest;
use App\Http\Requests\Engagements\TransitionEngagementRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\IntegrationConnection;
use App\Models\RateCardVersion;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EngagementController extends Controller
{
    /**
     * List all engagements, archived included — archived engagements stay
     * searchable, they just become read-only.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Engagement::class);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $organization = $user->organization;
        $search = trim($request->string('q')->value());
        $canCreate = $user->can('create', Engagement::class);

        return Inertia::render('engagements/index', [
            'engagements' => Engagement::query()
                ->with('customer')
                ->when($search !== '', fn (Builder $query) => $query->where(
                    fn (Builder $matches) => $matches
                        ->whereLike('name', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->whereLike('name', "%{$search}%"))
                ))
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Engagement $engagement): array => [
                    'id' => $engagement->id,
                    'name' => $engagement->name,
                    'status' => $engagement->status->value,
                    'statusLabel' => $engagement->status->label(),
                    'customerName' => $engagement->customer->name,
                ]),
            'plan' => [
                'label' => $organization->plan->label(),
                'activeCount' => $organization->activeEngagementCount(),
                'limit' => $organization->plan->activeEngagementLimit(),
            ],
            'customers' => $canCreate
                ? Customer::query()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Customer $customer): array => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                    ])
                : [],
            'filters' => [
                'q' => $search,
            ],
            'can' => [
                'create' => $canCreate,
            ],
        ]);
    }

    /**
     * Start a new engagement as a draft, within the plan's limit.
     */
    public function store(StoreEngagementRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $engagement = $user->organization->startEngagement($validated['name'], $validated['customer_id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Engagement :name created as a draft.', [
            'name' => $engagement->name,
        ])]);

        return to_route('engagements.show', $engagement);
    }

    public function show(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

        $baseline = $engagement->openBaseline() ?? $engagement->approvedBaseline();
        $finalAcceptance = $engagement->currentFinalAcceptance();

        return Inertia::render('engagements/show', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
                'customer' => [
                    'id' => $engagement->customer->id,
                    'name' => $engagement->customer->name,
                ],
                'createdAt' => $engagement->created_at?->toFormattedDateString(),
                'allowedTransitions' => collect($engagement->status->allowedTransitions())
                    ->map(fn (EngagementStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ]),
            ],
            'baseline' => $baseline === null ? null : [
                'id' => $baseline->id,
                'version' => $baseline->version,
                'status' => $baseline->status->value,
                'statusLabel' => $baseline->status->label(),
            ],
            'work' => [
                'itemCount' => $engagement->workItems()->count(),
                'unlinkedCount' => $engagement->workItems()->whereDoesntHave('link')->count(),
                'connections' => $engagement->integrationConnections
                    ->map(fn (IntegrationConnection $connection): array => [
                        'providerLabel' => $connection->provider->label(),
                        'status' => $connection->status->value,
                        'statusLabel' => $connection->status->label(),
                        'lastSyncedAt' => $connection->last_synced_at?->diffForHumans(),
                    ])
                    ->values(),
            ],
            'changeControl' => [
                'total' => $engagement->changeRequests()->count(),
                'open' => $engagement->changeRequests()
                    ->whereNotIn('status', [ChangeRequestStatus::Approved, ChangeRequestStatus::Rejected])
                    ->count(),
                'awaiting' => $engagement->changeRequests()
                    ->where('status', ChangeRequestStatus::AwaitingApproval)
                    ->count(),
            ],
            'acceptance' => [
                'total' => $engagement->deliverables()->count(),
                'accepted' => $engagement->deliverables()
                    ->where('status', DeliverableStatus::Accepted)
                    ->count(),
                'awaiting' => $engagement->deliverables()
                    ->where('status', DeliverableStatus::AwaitingAcceptance)
                    ->count(),
                'acceptedValue' => $engagement->acceptedValue()->toArray(),
                'finalAcceptance' => $finalAcceptance === null ? null : [
                    'id' => $finalAcceptance->id,
                    'status' => $finalAcceptance->status->value,
                    'statusLabel' => $finalAcceptance->status->label(),
                    'submittedAt' => $finalAcceptance->submitted_at?->toFormattedDateString(),
                    'respondBy' => $finalAcceptance->respond_by?->toFormattedDateString(),
                    'decidedAt' => $finalAcceptance->decided_at?->toFormattedDateString(),
                    'decidedBy' => $finalAcceptance->stakeholder_name,
                    'comment' => $finalAcceptance->comment,
                ],
            ],
            'governance' => [
                'decisions' => [
                    'total' => $engagement->decisions()->count(),
                    'drafts' => $engagement->decisions()->where('status', DecisionStatus::Draft)->count(),
                    'awaitingAcknowledgement' => $engagement->decisions()
                        ->where('visibility', RecordVisibility::Shared)
                        ->whereNot('status', DecisionStatus::Draft)
                        ->whereNull('acknowledged_at')
                        ->count(),
                ],
                'risks' => [
                    'live' => $engagement->risks()
                        ->whereIn('status', [RiskStatus::Open, RiskStatus::Mitigating])
                        ->count(),
                    'escalated' => $engagement->escalatedRisks()->count(),
                ],
                'dependencies' => [
                    'outstanding' => $engagement->dependencies()
                        ->whereIn('status', [DependencyStatus::Pending, DependencyStatus::Requested, DependencyStatus::Escalated])
                        ->count(),
                    'late' => $engagement->lateDependencies()->count(),
                    'customerOwed' => $engagement->customerOwedDependencies()->count(),
                ],
                'auditEntries' => AuditLog::query()->where('engagement_id', $engagement->id)->count(),
            ],
            'lifecycle' => collect(EngagementStatus::cases())
                ->map(fn (EngagementStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ]),
            'position' => $engagement->positionSummary($request->user()?->can('viewAny', RateCardVersion::class) ?? false),
            'can' => [
                'transition' => $request->user()?->can('transition', $engagement) ?? false,
                'viewCustomer' => $request->user()?->can('view', $engagement->customer) ?? false,
            ],
        ]);
    }

    /**
     * Move an engagement along its lifecycle.
     */
    public function transition(TransitionEngagementRequest $request, Engagement $engagement): RedirectResponse
    {
        $engagement->transitionTo(EngagementStatus::from($request->validated()['status']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Engagement moved to :status.', [
            'status' => $engagement->status->label(),
        ])]);

        return to_route('engagements.show', $engagement);
    }
}
