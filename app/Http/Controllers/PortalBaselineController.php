<?php

namespace App\Http\Controllers;

use App\Enums\BaselineDecision;
use App\Enums\BaselineStatus;
use App\Models\Baseline;
use App\Models\BaselineResponse;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's side of baseline approval (FA-5 step 6, FA-27): a
 * stakeholder with approval rights reviews the frozen customer-visible
 * snapshot — scope, milestones and contract value, never cost or margin —
 * and responds. Access is authenticated by the personally signed link from
 * the notification; the stakeholder and snapshot parameters are covered by
 * the signature, so neither can be swapped. Binding the link to the snapshot
 * means a page opened before a clarification round can never approve terms
 * it did not display — superseded links keep showing what they always
 * showed, read-only.
 */
class PortalBaselineController extends Controller
{
    /**
     * Show the frozen submission the link was issued for.
     */
    public function show(Request $request, Baseline $baseline, Stakeholder $stakeholder): Response
    {
        $this->authorizeStakeholder($baseline, $stakeholder);

        $snapshot = $this->signedSnapshot($request, $baseline);
        $isCurrent = $snapshot->id === $baseline->customer_snapshot_id;

        return Inertia::render('portal/baseline', [
            'submission' => $snapshot->payload,
            'baseline' => [
                'status' => $baseline->status->value,
                'statusLabel' => $baseline->status->label(),
                'submittedAt' => $baseline->submitted_at?->toFormattedDateString(),
                'approvedAt' => $baseline->approved_at?->toFormattedDateString(),
            ],
            'stakeholder' => [
                'name' => $stakeholder->name,
            ],
            /*
             * Only the decisions made against the snapshot this page shows:
             * a superseded link must never display a response — least of all
             * an approval — that belongs to a later revision's terms.
             */
            'responses' => $baseline->responses
                ->where('snapshot_id', $snapshot->id)
                ->map(fn (BaselineResponse $response): array => [
                    'id' => $response->id,
                    'decision' => $response->decision->value,
                    'decisionLabel' => $response->decision->label(),
                    'stakeholderName' => $response->stakeholder_name,
                    'comment' => $response->comment,
                    'respondedAt' => $response->created_at->toFormattedDateString(),
                ])
                ->values(),
            'superseded' => ! $isCurrent,
            'canRespond' => $isCurrent && $baseline->status === BaselineStatus::AwaitingApproval,
            'respondUrl' => URL::signedRoute('portal.baselines.respond', [
                'baseline' => $baseline->id,
                'stakeholder' => $stakeholder->id,
                'snapshot' => $snapshot->id,
            ]),
        ]);
    }

    /**
     * Record the approver's decision immutably against the frozen snapshot.
     */
    public function store(Request $request, Baseline $baseline, Stakeholder $stakeholder): RedirectResponse
    {
        $this->authorizeStakeholder($baseline, $stakeholder);

        $snapshot = $this->signedSnapshot($request, $baseline);

        if ($snapshot->id !== $baseline->customer_snapshot_id) {
            throw ValidationException::withMessages([
                'decision' => __('This baseline was revised after this page was opened — review the latest version from your most recent email.'),
            ]);
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(BaselineDecision::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = BaselineDecision::from((string) $validated['decision']);
        $comment = isset($validated['comment']) && is_string($validated['comment']) ? $validated['comment'] : null;

        /*
         * The snapshot travels into the model so the currency check happens
         * again under the row lock — the comparison above is only fast
         * feedback, and a revision landing between it and the lock must not
         * record this decision against terms the stakeholder never saw.
         */
        $baseline->recordResponse($stakeholder, $decision, $comment, $snapshot->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($decision) {
            BaselineDecision::Approved => __('Approved — thank you. This baseline is now the committed version the engagement runs against.'),
            BaselineDecision::Rejected => __('Rejected — your decision has been recorded and the delivery team will follow up.'),
            BaselineDecision::ClarificationRequested => __('Clarification requested — the delivery team has been informed.'),
        }]);

        return redirect()->to(URL::signedRoute('portal.baselines.show', [
            'baseline' => $baseline->id,
            'stakeholder' => $stakeholder->id,
            'snapshot' => $snapshot->id,
        ]));
    }

    /**
     * The customer snapshot this signed link was issued for. The id travels
     * as a signed query parameter, so it can only ever name a snapshot this
     * application put in a link — the lookup is still scoped to the baseline
     * and to customer-facing payloads as defence in depth.
     */
    private function signedSnapshot(Request $request, Baseline $baseline): Snapshot
    {
        $snapshot = $baseline->snapshots()
            ->whereKey((string) $request->query('snapshot'))
            ->first();

        if ($snapshot === null || ($snapshot->payload['kind'] ?? null) !== 'customer_review') {
            abort(404);
        }

        return $snapshot;
    }

    /**
     * The signature proves the link is genuine; this proves it belongs to
     * this baseline's customer and carries approval rights.
     */
    private function authorizeStakeholder(Baseline $baseline, Stakeholder $stakeholder): void
    {
        abort_unless(
            $stakeholder->organization_id === $baseline->organization_id
            && $stakeholder->customer_id === $baseline->engagement->customer_id
            && $stakeholder->role->canApprove(),
            403,
        );
    }
}
