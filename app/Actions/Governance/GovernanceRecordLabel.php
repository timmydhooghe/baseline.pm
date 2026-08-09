<?php

namespace App\Actions\Governance;

use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Decision;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Risk;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Model;

/**
 * What a linked governance record is called, in the words the product uses.
 * One definition serves both ends of a link: the picker that offers records
 * and the chip that renders one already linked, so a milestone never reads
 * as "Milestone" in one place and "BaselineItem" in the other.
 */
class GovernanceRecordLabel
{
    /**
     * The kind of record. Baseline items name their own type — a milestone
     * and an exclusion read very differently on a risk that threatens one.
     *
     * @param  string  $fallbackType  The morph class, for records that no longer exist.
     */
    public static function type(?Model $record, string $fallbackType): string
    {
        return match (true) {
            $record instanceof BaselineItem => $record->type->label(),
            $record instanceof Deliverable => __('Deliverable'),
            $record instanceof ChangeRequest => __('Change request'),
            $record instanceof Risk => __('Risk'),
            $record instanceof Dependency => __('Dependency'),
            $record instanceof Decision => __('Decision'),
            $record instanceof WorkItem => __('Work item'),
            default => class_basename($fallbackType),
        };
    }

    /**
     * What the record is called. A deliverable record carries no title of
     * its own — the contractual definition lives on its baseline item.
     */
    public static function title(?Model $record): string
    {
        return match (true) {
            $record instanceof Deliverable => $record->baselineItem->title,
            $record instanceof WorkItem => $record->external_key === null
                ? $record->title
                : "{$record->external_key} — {$record->title}",
            $record instanceof BaselineItem,
            $record instanceof ChangeRequest,
            $record instanceof Risk,
            $record instanceof Dependency,
            $record instanceof Decision => $record->title,
            default => __('Record no longer available'),
        };
    }

    /**
     * A record as the chip the ledgers render.
     *
     * @return array{type: string, type_label: string, id: string, title: string}
     */
    public static function chip(Model $record): array
    {
        return [
            'type' => $record->getMorphClass(),
            'type_label' => self::type($record, $record->getMorphClass()),
            'id' => (string) $record->getKey(),
            'title' => self::title($record),
        ];
    }
}
