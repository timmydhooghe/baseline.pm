<?php

namespace App\Actions\Governance;

use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Decision;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Model;

/**
 * The record chips the governance ledgers are built from (FA-19: "linked-record
 * chips and numeric effort fields — no free-text amounts"). One place decides
 * what an engagement offers to link to, so a decision, a risk and a dependency
 * all name records the same way.
 */
class LinkableRecords
{
    /**
     * Work items are the only unbounded set here; a picker showing every
     * imported issue would be unusable long before it was complete.
     */
    private const int WORK_ITEM_LIMIT = 100;

    /**
     * The engagement's records of the given types, as chips.
     *
     * @param  list<class-string<Model>>  $types
     * @return list<array{type: string, type_label: string, id: string, title: string}>
     */
    public static function forEngagement(Engagement $engagement, array $types): array
    {
        $records = [];

        foreach ($types as $type) {
            foreach (self::recordsOfType($engagement, $type) as $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Normalise validated link input into the shape the ledgers' syncLinks()
     * methods take.
     *
     * @param  array<int, array{type: string, id: string}>  $links
     * @return list<array{type: string, id: string}>
     */
    public static function targets(array $links): array
    {
        return array_values(array_map(
            fn (array $link): array => ['type' => $link['type'], 'id' => $link['id']],
            $links,
        ));
    }

    /**
     * @param  class-string<Model>  $type
     * @return array<int, array{type: string, type_label: string, id: string, title: string}>
     */
    private static function recordsOfType(Engagement $engagement, string $type): array
    {
        $records = match ($type) {
            BaselineItem::class => $engagement->currentBaseline()?->items()
                ->orderBy('type')
                ->orderBy('position')
                ->get(),
            Deliverable::class => $engagement->deliverables()->with('baselineItem')->get(),
            ChangeRequest::class => $engagement->changeRequests()->orderByDesc('created_at')->get(),
            Risk::class => $engagement->risks()->orderBy('title')->get(),
            Dependency::class => $engagement->dependencies()->orderBy('required_on')->get(),
            Decision::class => $engagement->decisions()->orderByDesc('created_at')->get(),
            WorkItem::class => $engagement->workItems()
                ->orderByDesc('created_at')
                ->limit(self::WORK_ITEM_LIMIT)
                ->get(),
            default => null,
        };

        return $records
            ?->map(fn (Model $record): array => GovernanceRecordLabel::chip($record))
            ->all() ?? [];
    }
}
