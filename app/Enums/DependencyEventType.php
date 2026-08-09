<?php

namespace App\Enums;

/**
 * An entry in a dependency's evidence trail (FA-20): the requests, reminders
 * and escalations that prove the item was chased, and the moment it arrived
 * or was waived. Every entry is appended — the trail is what makes a delay
 * attributable rather than merely asserted.
 */
enum DependencyEventType: string
{
    case Requested = 'requested';
    case Reminded = 'reminded';
    case Escalated = 'escalated';
    case Received = 'received';
    case Waived = 'waived';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Reminded => 'Reminded',
            self::Escalated => 'Escalated',
            self::Received => 'Received',
            self::Waived => 'Waived',
            self::Note => 'Note',
        };
    }

    /**
     * The status an event moves the dependency to, when it moves it at all —
     * reminders and notes leave the state alone.
     */
    public function resultingStatus(): ?DependencyStatus
    {
        return match ($this) {
            self::Requested => DependencyStatus::Requested,
            self::Escalated => DependencyStatus::Escalated,
            self::Received => DependencyStatus::Received,
            self::Waived => DependencyStatus::Waived,
            self::Reminded, self::Note => null,
        };
    }
}
