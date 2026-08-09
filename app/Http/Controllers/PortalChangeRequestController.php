<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestDecision;
use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestResponse;
use App\Models\Stakeholder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's side of change control (FA-13): a stakeholder with approval
 * rights reviews the frozen customer-visible snapshot — price, scope and
 * schedule, never cost or margin — and responds. Access is authenticated by
 * the personally signed link from the notification; the stakeholder route
 * parameter is covered by the signature, so it cannot be swapped.
 */
class PortalChangeRequestController extends Controller
{
    /**
     * Show the frozen proposal to the approver.
     */
    public function show(Request $request, ChangeRequest $changeRequest, Stakeholder $stakeholder): Response
    {
        $this->authorizeStakeholder($changeRequest, $stakeholder);

        $snapshot = $changeRequest->customerSnapshot;

        if ($snapshot === null) {
            abort(404);
        }

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
            'canRespond' => $changeRequest->status === ChangeRequestStatus::AwaitingApproval,
            /*
             * The respond link is minted for the snapshot on screen, so a
             * decision can only ever land on the proposal it was read from.
             */
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

        /*
         * A clarification request reopens the assessment, and resubmission
         * freezes a new proposal — often at a different price. Approval binds
         * the customer to what they actually read, so a form rendered from
         * the superseded snapshot is sent back to the current one.
         */
        if ($request->query('snapshot') !== $changeRequest->customer_snapshot_id) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('This proposal was revised after you opened it — please review the current version before deciding.')]);

            return redirect()->to(URL::signedRoute('portal.change-requests.show', [
                'changeRequest' => $changeRequest->id,
                'stakeholder' => $stakeholder->id,
            ]));
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
        ]));
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
