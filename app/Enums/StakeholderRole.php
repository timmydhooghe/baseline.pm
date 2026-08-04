<?php

namespace App\Enums;

enum StakeholderRole: string
{
    case ProjectManager = 'project_manager';
    case Approver = 'approver';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::ProjectManager => 'Project manager',
            self::Approver => 'Approver',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Whether this stakeholder may approve baselines and final acceptance.
     */
    public function canApprove(): bool
    {
        return match ($this) {
            self::ProjectManager, self::Approver => true,
            self::Viewer => false,
        };
    }
}
