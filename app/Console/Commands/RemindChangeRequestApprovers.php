<?php

namespace App\Console\Commands;

use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Nudge customer approvers about submitted change requests whose respond-by
 * deadline is near or past (FA-13), across all tenants. Runs hourly but
 * reminds each change request at most once a day — the 23-hour floor keeps
 * a daily rhythm without drifting later every day.
 */
class RemindChangeRequestApprovers extends Command
{
    protected $signature = 'change-requests:remind';

    protected $description = 'Remind customer approvers about change requests near or past their respond-by deadline';

    public function handle(): int
    {
        $due = ChangeRequest::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('status', ChangeRequestStatus::AwaitingApproval)
            ->whereNotNull('respond_by')
            ->where('respond_by', '<=', now()->addDays(ChangeRequest::REMINDER_LEAD_DAYS))
            ->where(function (Builder $query): void {
                $query->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', now()->subHours(23));
            })
            ->with('engagement.customer.stakeholders')
            ->get();

        foreach ($due as $changeRequest) {
            $changeRequest->remindApprovers();
        }

        $this->info("Sent reminders for {$due->count()} change requests.");

        return self::SUCCESS;
    }
}
