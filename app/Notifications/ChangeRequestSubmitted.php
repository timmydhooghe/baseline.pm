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
 * Tells a customer approver a change request proposal awaits their decision
 * (FA-13). The signed link authenticates the stakeholder personally; the
 * mail carries the customer-visible terms only.
 */
class ChangeRequestSubmitted extends Notification implements ShouldQueue
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
            throw new LogicException('Change request proposals are reviewed by stakeholders.');
        }

        $engagementName = $this->changeRequest->engagement->name;

        return (new MailMessage)
            ->subject(__('Change request for :engagement awaits your decision', ['engagement' => $engagementName]))
            ->line(__(':title — a change to :engagement — has been proposed at :price.', [
                'title' => $this->changeRequest->title,
                'engagement' => $engagementName,
                'price' => $this->changeRequest->customer_price?->format() ?? '—',
            ]))
            ->action(__('Review the proposal'), URL::signedRoute('portal.change-requests.show', [
                'changeRequest' => $this->changeRequest->id,
                'stakeholder' => $notifiable->id,
                'snapshot' => $this->changeRequest->customer_snapshot_id,
            ]))
            ->line(__('Please respond by :date. Your decision is recorded immutably against the frozen proposal.', [
                'date' => $this->changeRequest->respond_by?->toFormattedDateString() ?? '—',
            ]));
    }
}
