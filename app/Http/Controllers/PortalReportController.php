<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's side of weekly reporting (FA-26, FA-27): a stakeholder
 * reads the frozen customer snapshot — what moved, what changed, what is
 * owed — with cost and margin structurally absent. Access is authenticated
 * by the personally signed link from the notification; the stakeholder and
 * snapshot parameters are covered by the signature, so neither can be
 * swapped. Reports are read-only: there is nothing to respond to, the
 * record simply is.
 */
class PortalReportController extends Controller
{
    /**
     * Show the frozen report the link was issued for.
     */
    public function show(Request $request, Report $report, Stakeholder $stakeholder): Response
    {
        $this->authorizeStakeholder($report, $stakeholder);

        $snapshot = $this->signedSnapshot($request, $report);

        return Inertia::render('portal/report', [
            'report' => $snapshot->payload,
            'meta' => [
                'weekLabel' => $report->label(),
                'publishedAt' => $report->published_at->toFormattedDayDateString(),
            ],
            'stakeholder' => [
                'name' => $stakeholder->name,
            ],
        ]);
    }

    /**
     * The customer snapshot this signed link was issued for. The id travels
     * as a signed query parameter, so it can only ever name a snapshot this
     * application put in a link — the lookup is still scoped to the report
     * and to customer-facing payloads as defence in depth.
     */
    private function signedSnapshot(Request $request, Report $report): Snapshot
    {
        $snapshot = $report->snapshots()
            ->whereKey((string) $request->query('snapshot'))
            ->first();

        if ($snapshot === null || ($snapshot->payload['kind'] ?? null) !== 'customer_report') {
            abort(404);
        }

        return $snapshot;
    }

    /**
     * The signature proves the link is genuine; this proves it belongs to
     * this report's customer. Every stakeholder role may read reports —
     * viewing is the portal's floor, not a right to approve anything.
     */
    private function authorizeStakeholder(Report $report, Stakeholder $stakeholder): void
    {
        abort_unless(
            $stakeholder->organization_id === $report->organization_id
            && $stakeholder->customer_id === $report->engagement->customer_id,
            403,
        );
    }
}
