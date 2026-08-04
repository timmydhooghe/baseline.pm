<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Appends an audit log entry whenever the model is created, updated, or deleted.
 *
 * The payload contains the model's non-hidden attributes on create and delete,
 * and the changed attributes (with their previous values) on update.
 */
trait RecordsAuditLog
{
    public static function bootRecordsAuditLog(): void
    {
        static::created(function (Model $model): void {
            AuditLog::record('created', $model, $model->attributesToArray());
        });

        static::updated(function (Model $model): void {
            $changes = collect($model->getChanges())
                ->except([...$model->getHidden(), ...array_filter([$model->getUpdatedAtColumn()])])
                ->all();

            if ($changes === []) {
                return;
            }

            AuditLog::record('updated', $model, [
                'changes' => $changes,
                'previous' => collect($model->getOriginal())->only(array_keys($changes))->all(),
            ]);
        });

        static::deleted(function (Model $model): void {
            AuditLog::record('deleted', $model, $model->attributesToArray());
        });
    }
}
