<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\Stakeholder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * Tells a stakeholder the week's report is out (FA-26). The signed link
 * authenticates the stakeholder personally and is bound to the frozen
 * customer snapshot — what the mail announces is exactly what the link will
 * always show, cost and margin structurally absent.
 */
class WeeklyReportPublished extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Report $report) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        if (! $notifiable instanceof Stakeholder) {
            throw new LogicException('Weekly reports are sent to stakeholders.');
        }

        $engagementName = $this->report->engagement->name;

        return (new MailMessage)
            ->subject(__(':engagement — weekly report, :week', [
                'engagement' => $engagementName,
                'week' => $this->report->label(),
            ]))
            ->line(__('The weekly report for :engagement covering :week has been published: what moved, what changed, and what is owed — every line drawn from the engagement\'s records.', [
                'engagement' => $engagementName,
                'week' => $this->report->label(),
            ]))
            ->action(__('Read the report'), URL::signedRoute('portal.reports.show', [
                'report' => $this->report->id,
                'stakeholder' => $notifiable->id,
                'snapshot' => $this->report->customer_snapshot_id,
            ]))
            ->line(__('This report is a frozen record — it will always show exactly what was published today.'));
    }
}
