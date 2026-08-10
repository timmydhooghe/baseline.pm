<?php

namespace App\Notifications;

use App\Models\Baseline;
use App\Models\Stakeholder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * Tells a customer approver a baseline awaits their decision (FA-5 step 6,
 * FA-27). The signed link authenticates the stakeholder personally; the mail
 * carries the customer-visible commitments only — never cost or margin.
 */
class BaselineSubmitted extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Baseline $baseline) {}

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
            throw new LogicException('Baseline submissions are reviewed by stakeholders.');
        }

        $engagementName = $this->baseline->engagement->name;

        return (new MailMessage)
            ->subject(__('Baseline for :engagement awaits your approval', ['engagement' => $engagementName]))
            ->line(__('The delivery team has submitted baseline v:version for :engagement — the scope, milestones and commercial commitments the engagement will run against.', [
                'version' => $this->baseline->version,
                'engagement' => $engagementName,
            ]))
            ->action(__('Review the baseline'), URL::signedRoute('portal.baselines.show', [
                'baseline' => $this->baseline->id,
                'stakeholder' => $notifiable->id,
                'snapshot' => $this->baseline->customer_snapshot_id,
            ]))
            ->line(__('Approving commits the engagement to this version. Your decision is recorded immutably against the frozen submission.'));
    }
}
