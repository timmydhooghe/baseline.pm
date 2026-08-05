<?php

namespace App\Enums;

enum BaselineStatus: string
{
    case Draft = 'draft';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::AwaitingApproval => 'Awaiting approval',
            self::Approved => 'Approved',
        };
    }

    /**
     * A rejected or clarification-requested baseline returns to Draft — the
     * review snapshot is preserved, so there is no terminal "rejected" state.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::AwaitingApproval],
            self::AwaitingApproval => [self::Approved, self::Draft],
            self::Approved => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
