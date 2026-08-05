<?php

namespace App\Http\Controllers;

use App\Enums\BaselineItemType;
use App\Enums\IntegrationProvider;
use App\Enums\WorkItemState;
use App\Models\BaselineItem;
use App\Models\Engagement;
use App\Models\IntegrationConnection;
use App\Models\Release;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkController extends Controller
{
    /**
     * The engagement's execution work (FA-7, FA-8): integration connections
     * with their always-visible sync status, the imported and manual work
     * items with their deliverable mapping, and synced releases.
     */
    public function show(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $baseline = $engagement->currentBaseline();
        $deliverables = $baseline === null
            ? collect()
            : $baseline->items->where('type', BaselineItemType::Deliverable)->values();

        $workItems = $engagement->workItems()
            ->with(['worklogs', 'link.baselineItem', 'link.linkedBy'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('engagements/work', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
                'executionMode' => $baseline?->execution_mode->value,
                'executionModeLabel' => $baseline?->execution_mode->label(),
            ],
            'connections' => $engagement->integrationConnections
                ->map(fn (IntegrationConnection $connection): array => $this->connectionViewModel($connection))
                ->values(),
            'workItems' => $workItems
                ->map(fn (WorkItem $item): array => $this->workItemViewModel($item))
                ->values(),
            'releases' => $engagement->releases()
                ->orderByDesc('released_on')
                ->orderBy('name')
                ->get()
                ->map(fn (Release $release): array => [
                    'id' => $release->id,
                    'sourceLabel' => $release->source->label(),
                    'name' => $release->name,
                    'released' => $release->released,
                    'releasedOn' => $release->released_on?->toFormattedDateString(),
                    'externalUrl' => $release->external_url,
                ])
                ->values(),
            'mapping' => [
                'total' => $workItems->count(),
                'linked' => $workItems->filter(fn (WorkItem $item): bool => $item->link !== null)->count(),
                'unlinked' => $workItems->filter(fn (WorkItem $item): bool => $item->link === null)->count(),
            ],
            'deliverables' => $deliverables
                ->map(fn (BaselineItem $item): array => [
                    'id' => $item->id,
                    'title' => $item->title,
                ])
                ->values(),
            'baselineVersion' => $baseline?->version,
            'providers' => collect(IntegrationProvider::cases())
                ->map(fn (IntegrationProvider $provider): array => [
                    'value' => $provider->value,
                    'label' => $provider->label(),
                ]),
            'states' => collect(WorkItemState::cases())
                ->map(fn (WorkItemState $state): array => [
                    'value' => $state->value,
                    'label' => $state->label(),
                ]),
            'can' => [
                'manageIntegrations' => $user->can('create', [IntegrationConnection::class, $engagement]),
                'recordWork' => $user->can('create', [WorkItem::class, $engagement]),
                'linkWork' => $user->can('linkAny', [WorkItem::class, $engagement]),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionViewModel(IntegrationConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'provider' => $connection->provider->value,
            'providerLabel' => $connection->provider->label(),
            'status' => $connection->status->value,
            'statusLabel' => $connection->status->label(),
            'externalProjectKey' => $connection->external_project_key,
            'baseUrl' => $connection->base_url,
            'connectedByName' => $connection->connectedBy?->name,
            'connectedAt' => $connection->connected_at?->toFormattedDateString(),
            'disconnectedAt' => $connection->disconnected_at?->toFormattedDateString(),
            'lastSyncedAt' => $connection->last_synced_at?->diffForHumans(),
            'runs' => $connection->syncRuns()
                ->limit(5)
                ->get()
                ->map(fn (SyncRun $run): array => [
                    'id' => $run->id,
                    'status' => $run->status->value,
                    'statusLabel' => $run->status->label(),
                    'startedAt' => $run->started_at->diffForHumans(),
                    'counts' => $run->counts,
                    'error' => $run->error,
                ])
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workItemViewModel(WorkItem $item): array
    {
        $loggedSeconds = $item->loggedSeconds();

        return [
            'id' => $item->id,
            'source' => $item->source->value,
            'sourceLabel' => $item->source->label(),
            'externalKey' => $item->external_key,
            'externalUrl' => $item->external_url,
            'title' => $item->title,
            'state' => $item->state->value,
            'stateLabel' => $item->state->label(),
            'externalStatus' => $item->external_status,
            'type' => $item->type,
            'assigneeName' => $item->assignee_name,
            'estimate' => $item->estimate_value !== null && $item->estimate_unit !== null
                ? $item->estimate_unit->format($item->estimate_value)
                : null,
            'logged' => $loggedSeconds > 0 ? round($loggedSeconds / 3600, 1).'h' : null,
            'link' => $item->link === null ? null : [
                'deliverableId' => $item->link->baseline_item_id,
                'deliverableTitle' => $item->link->baselineItem->title,
                'linkedByName' => $item->link->linkedBy?->name,
                'linkedAt' => $item->link->created_at?->toFormattedDateString(),
            ],
        ];
    }
}
