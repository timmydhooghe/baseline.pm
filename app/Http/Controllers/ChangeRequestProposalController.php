<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestStatus;
use App\Http\Requests\ChangeRequests\UpdateChangeRequestProposalRequest;
use App\Models\ChangeRequest;
use App\ValueObjects\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ChangeRequestProposalController extends Controller
{
    /**
     * Set the customer price of the proposal (FA-12). The suggestion — cost
     * times the target margin the pinned sell rates embody — lives on the
     * page; what is stored is the number the manager deliberately set.
     */
    public function update(UpdateChangeRequestProposalRequest $request, ChangeRequest $changeRequest): RedirectResponse
    {
        if ($changeRequest->status !== ChangeRequestStatus::CustomerProposal) {
            throw ValidationException::withMessages([
                'customer_price' => __('Commercial terms are set while drafting the customer proposal.'),
            ]);
        }

        $euros = (float) $request->validated()['customer_price'];

        $changeRequest->update([
            'customer_price' => Money::fromCents((int) round($euros * 100)),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer price set to :price.', [
            'price' => $changeRequest->customer_price?->format() ?? '—',
        ])]);

        return to_route('change-requests.show', $changeRequest);
    }
}
