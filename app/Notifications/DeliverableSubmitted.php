<?php

namespace App\Notifications;

use App\Models\Deliverable;
use App\Models\Stakeholder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * Tells a customer approver a deliverable awaits their acceptance (FA-23).
 * The signed link authenticates the stakeholder personally; the mail
 * carries the customer-visible record only.
 */
class DeliverableSubmitted extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Deliverable $deliverable) {}

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
            throw new LogicException('Deliverable reviews are decided by stakeholders.');
        }

        $engagementName = $this->deliverable->engagement->name;

        return (new MailMessage)
            ->subject(__('Deliverable for :engagement awaits your acceptance', ['engagement' => $engagementName]))
            ->line(__(':title — a deliverable of :engagement — has been submitted for your review.', [
                'title' => $this->deliverable->baselineItem->title,
                'engagement' => $engagementName,
            ]))
            ->action(__('Review the deliverable'), URL::signedRoute('portal.deliverables.show', [
                'deliverable' => $this->deliverable->id,
                'stakeholder' => $notifiable->id,
            ]))
            ->line(__('Please respond by :date. Your decision is recorded immutably against the frozen record — accepting is signing.', [
                'date' => $this->deliverable->respond_by?->toFormattedDateString() ?? '—',
            ]));
    }
}
