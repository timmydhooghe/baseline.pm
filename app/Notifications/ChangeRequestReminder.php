<?php

namespace App\Notifications;

use App\Models\ChangeRequest;
use App\Models\Stakeholder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * Nudges a customer approver about a change request whose respond-by
 * deadline is near or past (FA-13).
 */
class ChangeRequestReminder extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ChangeRequest $changeRequest) {}

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
            throw new LogicException('Change request reminders go to stakeholders.');
        }

        $respondBy = $this->changeRequest->respond_by;
        $overdue = $respondBy !== null && $respondBy->isPast();

        return (new MailMessage)
            ->subject($overdue
                ? __('Overdue: change request for :engagement still awaits your decision', ['engagement' => $this->changeRequest->engagement->name])
                : __('Reminder: change request for :engagement awaits your decision', ['engagement' => $this->changeRequest->engagement->name]))
            ->line(__(':title is awaiting your approval, rejection or clarification request.', [
                'title' => $this->changeRequest->title,
            ]))
            ->line($overdue
                ? __('The respond-by deadline of :date has passed.', ['date' => $respondBy->toFormattedDateString()])
                : __('The respond-by deadline is :date.', ['date' => $respondBy?->toFormattedDateString() ?? '—']))
            ->action(__('Review the proposal'), URL::signedRoute('portal.change-requests.show', [
                'changeRequest' => $this->changeRequest->id,
                'stakeholder' => $notifiable->id,
                'snapshot' => $this->changeRequest->customer_snapshot_id,
            ]));
    }
}
