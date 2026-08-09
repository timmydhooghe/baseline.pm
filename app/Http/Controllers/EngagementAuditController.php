<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Engagement;
use App\Models\RateCardVersion;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The engagement's audit trail (FA-21): every governance action, append-only
 * and in order. Reachable from the engagement and from every record it
 * governs — a decision, a risk or a dependency filters the same trail down
 * to itself, so "what happened to this?" is one link away from everywhere.
 */
class EngagementAuditController extends Controller
{
    /**
     * How many entries one page of the trail carries.
     */
    private const int PER_PAGE = 100;

    public function show(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);
        Gate::authorize('viewAny', AuditLog::class);

        $action = trim($request->string('action')->value());
        $subjectId = trim($request->string('subject')->value());

        $entries = AuditLog::query()
            ->where('engagement_id', $engagement->id)
            ->when($action !== '', fn (Builder $query) => $query->whereLike('action', "{$action}%"))
            ->when($subjectId !== '', fn (Builder $query) => $query->where('subject_id', $subjectId))
            ->with('actor')
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('engagements/audit', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'entries' => $entries->through(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'subjectType' => class_basename($entry->subject_type),
                'subjectId' => $entry->subject_id,
                'actorName' => $entry->actor?->name,
                'payload' => $entry->payload,
                'recordedAt' => $entry->created_at->toDayDateTimeString(),
            ]),
            'actions' => AuditLog::query()
                ->where('engagement_id', $engagement->id)
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
            'filters' => [
                'action' => $action,
                'subject' => $subjectId,
            ],
            'position' => $engagement->positionSummary($request->user()?->can('viewAny', RateCardVersion::class) ?? false),
        ]);
    }
}
