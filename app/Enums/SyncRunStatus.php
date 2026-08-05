<?php

namespace App\Enums;

/**
 * The outcome of one sync pass against a provider. Runs are the visible sync
 * status FA-7 requires: the work page always shows the latest runs and their
 * result.
 */
enum SyncRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }
}
