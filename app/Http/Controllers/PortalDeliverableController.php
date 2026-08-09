<?php

namespace App\Http\Controllers;

use App\Enums\AcceptanceDecision;
use App\Enums\DeliverableStatus;
use App\Models\Deliverable;
use App\Models\DeliverableResponse;
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
 * The customer's side of deliverable acceptance (FA-23): a stakeholder with
 * approval rights reviews the frozen customer-visible record — progress,
 * criteria and shared evidence, never confidence, cost or internal evidence
 * — and responds. Access is authenticated by the personally signed link
 * from the notification; the stakeholder and snapshot parameters are covered
 * by the signature, so neither can be swapped. Binding the link to the
 * snapshot means a page opened before a rework round can never sign a record
 * it did not display — superseded links keep showing what they always
 * showed, read-only.
 */
class PortalDeliverableController extends Controller
{
    /**
     * Show the frozen record the link was issued for.
     */
    public function show(Request $request, Deliverable $deliverable, Stakeholder $stakeholder): Response
    {
        $this->authorizeStakeholder($deliverable, $stakeholder);

        $snapshot = $this->signedSnapshot($request, $deliverable);
        $isCurrent = $snapshot->id === $deliverable->customer_snapshot_id;

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
            'superseded' => ! $isCurrent,
            'canRespond' => $isCurrent && $deliverable->status === DeliverableStatus::AwaitingAcceptance,
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

        $snapshot = $this->signedSnapshot($request, $deliverable);

        if ($snapshot->id !== $deliverable->customer_snapshot_id) {
            throw ValidationException::withMessages([
                'decision' => __('This deliverable was revised after this page was opened — review the latest version from your most recent email.'),
            ]);
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
            'snapshot' => $snapshot->id,
        ]));
    }

    /**
     * The customer snapshot this signed link was issued for. The id travels
     * as a signed query parameter, so it can only ever name a snapshot this
     * application put in a link — the lookup is still scoped to the
     * deliverable and to customer-facing payloads as defence in depth.
     */
    private function signedSnapshot(Request $request, Deliverable $deliverable): Snapshot
    {
        $snapshot = $deliverable->snapshots()
            ->whereKey((string) $request->query('snapshot'))
            ->first();

        if ($snapshot === null || ($snapshot->payload['kind'] ?? null) !== 'customer_review') {
            abort(404);
        }

        return $snapshot;
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
