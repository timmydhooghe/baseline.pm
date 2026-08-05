<?php

namespace App\Enums;

/**
 * The provider-independent workflow state of a work item. Jira status
 * categories and Linear state types normalize onto these four so drift and
 * burn analysis never depend on provider-specific workflow names.
 */
enum WorkItemState: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Canceled => 'Canceled',
        };
    }
}
