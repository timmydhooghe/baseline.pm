<?php

namespace App\Enums;

enum EngagementStatus: string
{
    case Draft = 'draft';
    case PreparingBaseline = 'preparing_baseline';
    case AwaitingBaselineApproval = 'awaiting_baseline_approval';
    case Active = 'active';
    case AwaitingFinalAcceptance = 'awaiting_final_acceptance';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PreparingBaseline => 'Preparing baseline',
            self::AwaitingBaselineApproval => 'Awaiting baseline approval',
            self::Active => 'Active',
            self::AwaitingFinalAcceptance => 'Awaiting final acceptance',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /**
     * A rejected, clarification-requested or withdrawn baseline submission
     * moves the engagement back from awaiting approval to preparing; a
     * rejected, clarified or withdrawn final acceptance moves it back from
     * awaiting final acceptance to active (FA-24).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PreparingBaseline],
            self::PreparingBaseline => [self::AwaitingBaselineApproval],
            self::AwaitingBaselineApproval => [self::Active, self::PreparingBaseline],
            self::Active => [self::AwaitingFinalAcceptance],
            self::AwaitingFinalAcceptance => [self::Completed, self::Active],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Archived engagements are read-only and free up their plan slot.
     */
    public function countsTowardPlanLimit(): bool
    {
        return $this !== self::Archived;
    }

    /**
     * Whether the engagement has reached the customer's portal (FA-27):
     * nothing is shared before the first baseline submission asks for their
     * approval, and completed work stays visible as read-only history.
     */
    public function isPortalVisible(): bool
    {
        return ! in_array($this, [self::Draft, self::PreparingBaseline], true);
    }

    /**
     * The states the portal may show, for whereIn constraints.
     *
     * @return list<self>
     */
    public static function portalVisible(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => $status->isPortalVisible(),
        ));
    }
}
