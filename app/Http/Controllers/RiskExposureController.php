<?php

namespace App\Http\Controllers;

use App\Http\Requests\Risks\UpdateRiskExposureRequest;
use App\Models\RateCardVersion;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The structured exposure behind a risk (FA-19): days per rate card role.
 * The euro figure is derived from the pinned version, never typed — and
 * because it derives from cost rates, setting it stays with the roles that
 * may read the rate card.
 */
class RiskExposureController extends Controller
{
    public function update(UpdateRiskExposureRequest $request, Risk $risk): RedirectResponse
    {
        Gate::authorize('update', $risk);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if (! $user->can('viewAny', RateCardVersion::class)) {
            throw ValidationException::withMessages([
                'lines' => __('Exposure prices against the rate card — it is set by the roles that may read it.'),
            ]);
        }

        $risk->syncExposures($request->validated()['lines'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Exposure set — :weighted weighted into the margin risk band.', [
            'weighted' => $risk->weightedExposure()->format(),
        ])]);

        return to_route('risks.show', $risk);
    }
}
