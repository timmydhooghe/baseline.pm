<?php

namespace App\Http\Controllers;

use App\Enums\AcceptanceDecision;
use App\Enums\DeliverableStatus;
use App\Models\Deliverable;
use App\Models\DeliverableResponse;
use App\Models\Stakeholder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's side of deliverable acceptance (FA-23): a stakeholder with
 * approval rights reviews the frozen customer-visible record — progress,
 * criteria and shared evidence, never confidence, cost or internal evidence
 * — and responds. Access is authenticated by the personally signed link
 * from the notification; the stakeholder route parameter is covered by the
 * signature, so it cannot be swapped.
 */
class PortalDeliverableController extends Controller
{
    /**
     * Show the frozen record to the approver.
     */
    public function show(Request $request, Deliverable $deliverable, Stakeholder $stakeholder): Response
    {
        $this->authorizeStakeholder($deliverable, $stakeholder);

        $snapshot = $deliverable->customerSnapshot;

        if ($snapshot === null) {
            abort(404);
        }

        return Inertia::render('portal/deliverable', [
            'review' => $snapshot->payload,
            'deliverable' => [
                'status' => $deliverable->status->value,
                'statusLabel' => $deliverable->status->label(),
                'respondBy' => $deliverable->respond_by?->toFormattedDateString(),
                'respondByOverdue' => $deliverable->status === DeliverableStatus::AwaitingAcceptance
                    && ($deliverable->respond_by?->isPast() ?? false),
                'acceptedAt' => $deliverable->accepted_at?->toFormattedDateString(),
                'decidedAt' => $deliverable->decided_at?->toFormattedDateString(),
            ],
            'stakeholder' => [
                'name' => $stakeholder->name,
            ],
            'responses' => $deliverable->responses
                ->map(fn (DeliverableResponse $response): array => [
                    'id' => $response->id,
                    'decision' => $response->decision->value,
                    'decisionLabel' => $response->decision->label(),
                    'stakeholderName' => $response->stakeholder_name,
                    'comment' => $response->comment,
                    'respondedAt' => $response->created_at->toFormattedDateString(),
                ])
                ->values(),
            'canRespond' => $deliverable->status === DeliverableStatus::AwaitingAcceptance,
            /*
             * The respond link is minted for the snapshot on screen, so a
             * signature can only ever land on the record it was read from.
             */
            'respondUrl' => URL::signedRoute('portal.deliverables.respond', [
                'deliverable' => $deliverable->id,
                'stakeholder' => $stakeholder->id,
                'snapshot' => $snapshot->id,
            ]),
        ]);
    }

    /**
     * Record the approver's decision immutably against the frozen snapshot.
     */
    public function store(Request $request, Deliverable $deliverable, Stakeholder $stakeholder): RedirectResponse
    {
        $this->authorizeStakeholder($deliverable, $stakeholder);

        /*
         * A deliverable that was withdrawn, reworked and resubmitted carries
         * a different frozen record than the one this form was rendered from.
         * Signing binds the reviewer to what they actually read, so a stale
         * form is sent back to the current version instead of deciding on it.
         */
        if ($request->query('snapshot') !== $deliverable->customer_snapshot_id) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('This deliverable was revised after you opened it — please review the current version before deciding.')]);

            return redirect()->to(URL::signedRoute('portal.deliverables.show', [
                'deliverable' => $deliverable->id,
                'stakeholder' => $stakeholder->id,
            ]));
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(AcceptanceDecision::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = AcceptanceDecision::from((string) $validated['decision']);
        $comment = isset($validated['comment']) && is_string($validated['comment']) ? $validated['comment'] : null;

        $deliverable->recordResponse($stakeholder, $decision, $comment);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($decision) {
            AcceptanceDecision::Accepted => __('Accepted — thank you. Your signature is on record and the deliverable counts as delivered.'),
            AcceptanceDecision::Rejected => __('Rejected — your decision has been recorded and the delivery team will rework the deliverable.'),
            AcceptanceDecision::ClarificationRequested => __('Clarification requested — the delivery team has been informed.'),
        }]);

        return redirect()->to(URL::signedRoute('portal.deliverables.show', [
            'deliverable' => $deliverable->id,
            'stakeholder' => $stakeholder->id,
        ]));
    }

    /**
     * The signature proves the link is genuine; this proves it belongs to
     * this deliverable's customer and carries approval rights.
     */
    private function authorizeStakeholder(Deliverable $deliverable, Stakeholder $stakeholder): void
    {
        abort_unless(
            $stakeholder->organization_id === $deliverable->organization_id
            && $stakeholder->customer_id === $deliverable->engagement->customer_id
            && $stakeholder->role->canApprove(),
            403,
        );
    }
}
