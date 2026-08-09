<?php

namespace App\Http\Controllers;

use App\Enums\DecisionStatus;
use App\Models\Decision;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's side of a shared decision (FA-18): the frozen record they
 * were given, and their acknowledgment of it. Acknowledgment is not
 * approval — it is the customer confirming they have seen what was decided
 * — but it is recorded immutably against the payload they actually saw.
 *
 * Access runs on a personally signed link covering the stakeholder and the
 * snapshot, so neither can be swapped, and budget impact never travels in
 * the payload: the portal carries no money the customer did not agree to.
 */
class PortalDecisionController extends Controller
{
    public function show(Request $request, Decision $decision, Stakeholder $stakeholder): Response
    {
        $this->authorizeStakeholder($decision, $stakeholder);

        $snapshot = $this->signedSnapshot($request, $decision);
        $superseded = $decision->status === DecisionStatus::Superseded
            || $snapshot->id !== $decision->customer_snapshot_id;

        return Inertia::render('portal/decision', [
            'record' => $snapshot->payload,
            'decision' => [
                'statusLabel' => $decision->status->label(),
                'acknowledgedAt' => $decision->acknowledged_at?->toFormattedDateString(),
                'acknowledgedByName' => $decision->acknowledged_by_name,
                'acknowledgementComment' => $decision->acknowledgement_comment,
            ],
            'stakeholder' => [
                'name' => $stakeholder->name,
            ],
            'superseded' => $superseded,
            'canAcknowledge' => ! $superseded && $decision->acknowledged_at === null,
            'acknowledgeUrl' => URL::signedRoute('portal.decisions.acknowledge', [
                'decision' => $decision->id,
                'stakeholder' => $stakeholder->id,
                'snapshot' => $snapshot->id,
            ]),
        ]);
    }

    /**
     * Record the acknowledgment against the frozen record.
     */
    public function store(Request $request, Decision $decision, Stakeholder $stakeholder): RedirectResponse
    {
        $this->authorizeStakeholder($decision, $stakeholder);

        $snapshot = $this->signedSnapshot($request, $decision);

        abort_unless(
            $snapshot->id === $decision->customer_snapshot_id
            && $decision->status === DecisionStatus::Confirmed,
            404,
        );

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $comment = isset($validated['comment']) && is_string($validated['comment']) ? $validated['comment'] : null;

        $decision->acknowledge($stakeholder, $comment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Acknowledged — thank you. Your confirmation is on the record.')]);

        return redirect()->to(URL::signedRoute('portal.decisions.show', [
            'decision' => $decision->id,
            'stakeholder' => $stakeholder->id,
            'snapshot' => $snapshot->id,
        ]));
    }

    /**
     * The customer snapshot this signed link was issued for. The id travels
     * as a signed query parameter, so it can only ever name a snapshot this
     * application put in a link — the lookup is still scoped to the decision
     * and to customer-facing payloads as defence in depth.
     */
    private function signedSnapshot(Request $request, Decision $decision): Snapshot
    {
        $snapshot = $decision->snapshots()
            ->whereKey((string) $request->query('snapshot'))
            ->first();

        if ($snapshot === null || ($snapshot->payload['kind'] ?? null) !== 'customer_decision') {
            abort(404);
        }

        return $snapshot;
    }

    /**
     * The signature proves the link is genuine; this proves it belongs to
     * this engagement's customer and that the record was ever shared.
     */
    private function authorizeStakeholder(Decision $decision, Stakeholder $stakeholder): void
    {
        abort_unless(
            $stakeholder->organization_id === $decision->organization_id
            && $stakeholder->customer_id === $decision->engagement->customer_id
            && $decision->visibility->isShared()
            && $decision->status->isConfirmed(),
            403,
        );
    }
}
