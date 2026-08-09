<?php

namespace App\Http\Controllers;

use App\Actions\Governance\ProposeDecisionsFromTranscript;
use App\Http\Requests\Decisions\ProposeDecisionsRequest;
use App\Models\Engagement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Proposing decision drafts from a meeting transcript (FA-18). The
 * extraction proposes; a human confirms. Every proposal keeps the excerpt it
 * came from, so a reader can check the claim against what was actually said
 * before putting it on the ledger.
 */
class DecisionTranscriptController extends Controller
{
    public function store(ProposeDecisionsRequest $request, Engagement $engagement): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $proposed = $engagement->proposeDecisionsFromTranscript($request->validated()['transcript'], $user);

        Inertia::flash('toast', $proposed->isEmpty()
            ? [
                'type' => 'info',
                'message' => __('No decisions found in that transcript — nothing in it closed anything. Record the decision by hand instead.'),
            ]
            : [
                'type' => 'success',
                'message' => trans_choice(
                    '{1}One decision draft proposed — read it against the excerpt and confirm.|[2,*]:count decision drafts proposed — read each against its excerpt and confirm.',
                    $proposed->count(),
                    ['count' => $proposed->count()],
                ),
            ]);

        if ($proposed->count() === ProposeDecisionsFromTranscript::MAX_PROPOSALS) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('The first :count decisions in that transcript were proposed — confirm or discard them, then paste the rest.', [
                    'count' => ProposeDecisionsFromTranscript::MAX_PROPOSALS,
                ]),
            ]);
        }

        return to_route('engagements.decisions.index', $engagement);
    }
}
