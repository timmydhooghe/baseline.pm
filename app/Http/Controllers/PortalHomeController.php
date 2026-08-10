<?php

namespace App\Http\Controllers;

use App\Enums\BaselineStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\DeliverableStatus;
use App\Enums\EngagementStatus;
use App\Enums\FinalAcceptanceStatus;
use App\Models\Baseline;
use App\Models\ChangeRequest;
use App\Models\Deliverable;
use App\Models\Engagement;
use App\Models\Stakeholder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in stakeholder's portal overview (FA-27): every engagement
 * their customer can see, with what awaits them on each. Everything is
 * resolved through the stakeholder's own customer record, so another
 * customer's engagements are unreachable by construction — and no figure on
 * this page is ever cost, rate or margin.
 */
class PortalHomeController extends Controller
{
    /**
     * List the customer's engagements, or go straight to the only one.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $stakeholder = $request->user('stakeholder');

        if (! $stakeholder instanceof Stakeholder) {
            abort(403);
        }

        $engagements = $stakeholder->customer->engagements()
            ->whereIn('status', EngagementStatus::portalVisible())
            ->with(['baselines', 'changeRequests', 'deliverables', 'finalAcceptances', 'dependencies.responsibleStakeholder', 'reports'])
            ->orderBy('name')
            ->get();

        if ($engagements->count() === 1) {
            return redirect()->route('portal.engagements.show', ['engagement' => $engagements->sole()->id]);
        }

        return Inertia::render('portal/home', [
            'stakeholder' => [
                'name' => $stakeholder->name,
                'roleLabel' => $stakeholder->role->label(),
            ],
            'customer' => [
                'name' => $stakeholder->customer->name,
            ],
            'organization' => [
                'name' => $stakeholder->organization->name,
            ],
            'engagements' => $engagements
                ->map(fn (Engagement $engagement): array => [
                    'id' => $engagement->id,
                    'name' => $engagement->name,
                    'status' => $engagement->status->value,
                    'statusLabel' => $engagement->status->label(),
                    'baselineVersion' => $engagement->approvedBaseline()?->version,
                    'awaitingCount' => $this->awaitingCount($engagement),
                    'owedCount' => $engagement->customerOwedDependencies()->count(),
                    'lastReport' => $engagement->reports->sortByDesc('week_start')->first()?->label(),
                ])
                ->values(),
        ]);
    }

    /**
     * How many records currently await a customer decision on this
     * engagement: a submitted baseline, proposed changes, deliverables under
     * review and an open final acceptance.
     */
    private function awaitingCount(Engagement $engagement): int
    {
        return $engagement->baselines->filter(fn (Baseline $baseline): bool => $baseline->status === BaselineStatus::AwaitingApproval)->count()
            + $engagement->changeRequests->filter(fn (ChangeRequest $changeRequest): bool => $changeRequest->status === ChangeRequestStatus::AwaitingApproval)->count()
            + $engagement->deliverables->filter(fn (Deliverable $deliverable): bool => $deliverable->status === DeliverableStatus::AwaitingAcceptance)->count()
            + ($engagement->currentFinalAcceptance()?->status === FinalAcceptanceStatus::AwaitingResponse ? 1 : 0);
    }
}
