<?php

namespace App\Http\Controllers;

use App\Enums\AcceptanceDecision;
use App\Models\FinalAcceptance;
use App\Models\Stakeholder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's side of final acceptance (FA-24): a stakeholder with
 * approval rights reviews the frozen engagement record — every signed
 * deliverable acceptance, never cost or margin — and signs off. Acceptance
 * completes the engagement. Access is authenticated by the personally
 * signed link from the notification.
 */
class PortalFinalAcceptanceController extends Controller
{
    /**
     * Show the frozen record to the approver.
     */
    public function show(Request $request, FinalAcceptance $finalAcceptance, Stakeholder $stakeholder): Response
    {
        $this->authorizeStakeholder($finalAcceptance, $stakeholder);

        $snapshot = $finalAcceptance->customerSnapshot;

        if ($snapshot === null) {
            abort(404);
        }

        return Inertia::render('portal/final-acceptance', [
            'record' => $snapshot->payload,
            'finalAcceptance' => [
                'status' => $finalAcceptance->status->value,
                'statusLabel' => $finalAcceptance->status->label(),
                'respondBy' => $finalAcceptance->respond_by?->toFormattedDateString(),
                'respondByOverdue' => $finalAcceptance->status->isOpen()
                    && ($finalAcceptance->respond_by?->isPast() ?? false),
                'decidedAt' => $finalAcceptance->decided_at?->toFormattedDateString(),
                'decision' => $finalAcceptance->decision?->value,
                'decisionLabel' => $finalAcceptance->decision?->label(),
                'decidedBy' => $finalAcceptance->stakeholder_name,
                'comment' => $finalAcceptance->comment,
            ],
            'stakeholder' => [
                'name' => $stakeholder->name,
            ],
            'canRespond' => $finalAcceptance->status->isOpen(),
            'respondUrl' => URL::signedRoute('portal.final-acceptances.respond', [
                'finalAcceptance' => $finalAcceptance->id,
                'stakeholder' => $stakeholder->id,
            ]),
        ]);
    }

    /**
     * Record the approver's decision immutably against the frozen snapshot.
     */
    public function store(Request $request, FinalAcceptance $finalAcceptance, Stakeholder $stakeholder): RedirectResponse
    {
        $this->authorizeStakeholder($finalAcceptance, $stakeholder);

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(AcceptanceDecision::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = AcceptanceDecision::from((string) $validated['decision']);
        $comment = isset($validated['comment']) && is_string($validated['comment']) ? $validated['comment'] : null;

        $finalAcceptance->recordResponse($stakeholder, $decision, $comment);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($decision) {
            AcceptanceDecision::Accepted => __('Accepted — thank you. Your signature completes the engagement.'),
            AcceptanceDecision::Rejected => __('Rejected — your decision has been recorded and the delivery team has been informed.'),
            AcceptanceDecision::ClarificationRequested => __('Clarification requested — the delivery team has been informed.'),
        }]);

        return redirect()->to(URL::signedRoute('portal.final-acceptances.show', [
            'finalAcceptance' => $finalAcceptance->id,
            'stakeholder' => $stakeholder->id,
        ]));
    }

    /**
     * The signature proves the link is genuine; this proves it belongs to
     * this engagement's customer and carries approval rights.
     */
    private function authorizeStakeholder(FinalAcceptance $finalAcceptance, Stakeholder $stakeholder): void
    {
        abort_unless(
            $stakeholder->organization_id === $finalAcceptance->organization_id
            && $stakeholder->customer_id === $finalAcceptance->engagement->customer_id
            && $stakeholder->role->canApprove(),
            403,
        );
    }
}
