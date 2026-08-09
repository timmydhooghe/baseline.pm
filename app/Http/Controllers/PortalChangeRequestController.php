<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestDecision;
use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestResponse;
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
 * The customer's side of change control (FA-13): a stakeholder with approval
 * rights reviews the frozen customer-visible snapshot — price, scope and
 * schedule, never cost or margin — and responds. Access is authenticated by
 * the personally signed link from the notification; the stakeholder and
 * snapshot parameters are covered by the signature, so neither can be
 * swapped. Binding the link to the snapshot means a page opened before a
 * clarification round can never decide on terms it did not display —
 * superseded links keep showing what they always showed, read-only.
 */
class PortalChangeRequestController extends Controller
{
    /**
     * Show the frozen proposal the link was issued for.
     */
    public function show(Request $request, ChangeRequest $changeRequest, Stakeholder $stakeholder): Response
    {
        $this->authorizeStakeholder($changeRequest, $stakeholder);

        $snapshot = $this->signedSnapshot($request, $changeRequest);
        $isCurrent = $snapshot->id === $changeRequest->customer_snapshot_id;

        return Inertia::render('portal/change-request', [
            'proposal' => $snapshot->payload,
            'changeRequest' => [
                'status' => $changeRequest->status->value,
                'statusLabel' => $changeRequest->status->label(),
                'respondBy' => $changeRequest->respond_by?->toFormattedDateString(),
                'respondByOverdue' => $changeRequest->status === ChangeRequestStatus::AwaitingApproval
                    && ($changeRequest->respond_by?->isPast() ?? false),
                'decidedAt' => $changeRequest->decided_at?->toFormattedDateString(),
            ],
            'stakeholder' => [
                'name' => $stakeholder->name,
            ],
            'responses' => $changeRequest->responses
                ->map(fn (ChangeRequestResponse $response): array => [
                    'id' => $response->id,
                    'decision' => $response->decision->value,
                    'decisionLabel' => $response->decision->label(),
                    'stakeholderName' => $response->stakeholder_name,
                    'comment' => $response->comment,
                    'respondedAt' => $response->created_at->toFormattedDateString(),
                ])
                ->values(),
            'superseded' => ! $isCurrent,
            'canRespond' => $isCurrent && $changeRequest->status === ChangeRequestStatus::AwaitingApproval,
            'respondUrl' => URL::signedRoute('portal.change-requests.respond', [
                'changeRequest' => $changeRequest->id,
                'stakeholder' => $stakeholder->id,
                'snapshot' => $snapshot->id,
            ]),
        ]);
    }

    /**
     * Record the approver's decision immutably against the frozen snapshot.
     */
    public function store(Request $request, ChangeRequest $changeRequest, Stakeholder $stakeholder): RedirectResponse
    {
        $this->authorizeStakeholder($changeRequest, $stakeholder);

        $snapshot = $this->signedSnapshot($request, $changeRequest);

        if ($snapshot->id !== $changeRequest->customer_snapshot_id) {
            throw ValidationException::withMessages([
                'decision' => __('This proposal was revised after this page was opened — review the latest version from your most recent email.'),
            ]);
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(ChangeRequestDecision::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = ChangeRequestDecision::from((string) $validated['decision']);
        $comment = isset($validated['comment']) && is_string($validated['comment']) ? $validated['comment'] : null;

        $changeRequest->recordResponse($stakeholder, $decision, $comment);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($decision) {
            ChangeRequestDecision::Approved => __('Approved — thank you. The agreed change is now part of the committed baseline.'),
            ChangeRequestDecision::Rejected => __('Rejected — your decision has been recorded.'),
            ChangeRequestDecision::ClarificationRequested => __('Clarification requested — the delivery team has been informed.'),
        }]);

        return redirect()->to(URL::signedRoute('portal.change-requests.show', [
            'changeRequest' => $changeRequest->id,
            'stakeholder' => $stakeholder->id,
            'snapshot' => $snapshot->id,
        ]));
    }

    /**
     * The customer snapshot this signed link was issued for. The id travels
     * as a signed query parameter, so it can only ever name a snapshot this
     * application put in a link — the lookup is still scoped to the change
     * request and to customer-facing payloads as defence in depth.
     */
    private function signedSnapshot(Request $request, ChangeRequest $changeRequest): Snapshot
    {
        $snapshot = $changeRequest->snapshots()
            ->whereKey((string) $request->query('snapshot'))
            ->first();

        if ($snapshot === null || ($snapshot->payload['kind'] ?? null) !== 'customer_review') {
            abort(404);
        }

        return $snapshot;
    }

    /**
     * The signature proves the link is genuine; this proves it belongs to
     * this change request's customer and carries approval rights.
     */
    private function authorizeStakeholder(ChangeRequest $changeRequest, Stakeholder $stakeholder): void
    {
        abort_unless(
            $stakeholder->organization_id === $changeRequest->organization_id
            && $stakeholder->customer_id === $changeRequest->engagement->customer_id
            && $stakeholder->role->canApprove(),
            403,
        );
    }
}
