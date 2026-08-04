<?php

namespace App\Http\Controllers;

use App\Enums\EngagementStatus;
use App\Http\Requests\Engagements\StoreEngagementRequest;
use App\Http\Requests\Engagements\TransitionEngagementRequest;
use App\Models\Customer;
use App\Models\Engagement;
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

        $engagement = new Engagement(['name' => $validated['name']]);
        $engagement->customer_id = $validated['customer_id'];
        $engagement->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Engagement :name created as a draft.', [
            'name' => $engagement->name,
        ])]);

        return to_route('engagements.show', $engagement);
    }

    public function show(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

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
            'lifecycle' => collect(EngagementStatus::cases())
                ->map(fn (EngagementStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ]),
            'can' => [
                'transition' => $request->user()?->can('transition', $engagement) ?? false,
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
