<?php

namespace App\Http\Controllers;

use App\Enums\DependencyEventType;
use App\Http\Requests\Dependencies\StoreDependencyEventRequest;
use App\Models\Dependency;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The evidence trail of a dependency (FA-20). Requests, reminders and
 * escalations are appended and never rewritten: "we asked four times, here
 * is when" is the difference between an attributed delay and an argument.
 */
class DependencyEventController extends Controller
{
    public function store(StoreDependencyEventRequest $request, Dependency $dependency): RedirectResponse
    {
        Gate::authorize('update', $dependency);

        $validated = $request->validated();
        $type = DependencyEventType::from($validated['type']);
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $dependency->recordEvent($type, $validated, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($type) {
            DependencyEventType::Received => __('Received — the delay clock stopped at :days days.', [
                'days' => $dependency->delayDays(),
            ]),
            DependencyEventType::Waived => __('Waived — the item is off the register and its trail stays on record.'),
            DependencyEventType::Escalated => __('Escalated — on record, attributed to :party.', [
                'party' => $dependency->attribution()->label(),
            ]),
            default => __(':type recorded on the evidence trail.', ['type' => $type->label()]),
        }]);

        return to_route('dependencies.show', $dependency);
    }
}
