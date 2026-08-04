<?php

namespace App\Http\Controllers;

use App\Enums\StakeholderRole;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Stakeholder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    /**
     * List the organization's customer records.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        return Inertia::render('customers/index', [
            'customers' => Customer::query()
                ->withCount(['stakeholders', 'engagements'])
                ->orderBy('name')
                ->get()
                ->map(fn (Customer $customer): array => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'stakeholderCount' => $customer->stakeholders_count,
                    'engagementCount' => $customer->engagements_count,
                ]),
            'can' => [
                'manage' => $request->user()?->can('create', Customer::class) ?? false,
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = Customer::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer :name created.', [
            'name' => $customer->name,
        ])]);

        return to_route('customers.show', $customer);
    }

    /**
     * Show a customer with its stakeholder list and engagements.
     */
    public function show(Request $request, Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        return Inertia::render('customers/show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
            ],
            'stakeholders' => $customer->stakeholders()
                ->orderBy('name')
                ->get()
                ->map(fn (Stakeholder $stakeholder): array => [
                    'id' => $stakeholder->id,
                    'name' => $stakeholder->name,
                    'email' => $stakeholder->email,
                    'role' => $stakeholder->role->value,
                    'roleLabel' => $stakeholder->role->label(),
                ]),
            'engagements' => $customer->engagements()
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Engagement $engagement): array => [
                    'id' => $engagement->id,
                    'name' => $engagement->name,
                    'status' => $engagement->status->value,
                    'statusLabel' => $engagement->status->label(),
                ]),
            'stakeholderRoles' => collect(StakeholderRole::cases())
                ->map(fn (StakeholderRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ]),
            'can' => [
                'manage' => $request->user()?->can('update', $customer) ?? false,
            ],
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer updated.')]);

        return to_route('customers.show', $customer);
    }

    /**
     * Delete a customer. Customers with engagements are commercial history
     * and cannot be removed (the database restricts it as well).
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        if ($customer->engagements()->exists()) {
            throw ValidationException::withMessages([
                'customer' => __('This customer still has engagements. Archive them first; engagements cannot be detached.'),
            ]);
        }

        $customer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer :name deleted.', [
            'name' => $customer->name,
        ])]);

        return to_route('customers.index');
    }
}
