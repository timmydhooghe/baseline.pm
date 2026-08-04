<?php

namespace App\Http\Controllers;

use App\Http\Requests\Stakeholders\StoreStakeholderRequest;
use App\Http\Requests\Stakeholders\UpdateStakeholderRequest;
use App\Models\Customer;
use App\Models\Stakeholder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class StakeholderController extends Controller
{
    /**
     * Add an external stakeholder to a customer record. Stakeholders never
     * consume paid seats.
     */
    public function store(StoreStakeholderRequest $request, Customer $customer): RedirectResponse
    {
        $stakeholder = new Stakeholder($request->validated());
        $stakeholder->customer()->associate($customer);
        $stakeholder->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added as :role.', [
            'name' => $stakeholder->name,
            'role' => $stakeholder->role->label(),
        ])]);

        return to_route('customers.show', $customer);
    }

    public function update(UpdateStakeholderRequest $request, Stakeholder $stakeholder): RedirectResponse
    {
        $stakeholder->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stakeholder updated.')]);

        return to_route('customers.show', $stakeholder->customer_id);
    }

    public function destroy(Stakeholder $stakeholder): RedirectResponse
    {
        Gate::authorize('delete', $stakeholder);

        $stakeholder->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed.', [
            'name' => $stakeholder->name,
        ])]);

        return to_route('customers.show', $stakeholder->customer_id);
    }
}
