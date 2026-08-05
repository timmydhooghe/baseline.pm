<?php

namespace App\Notifications;

use App\Models\FinalAcceptance;
use App\Models\Stakeholder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * Tells a customer approver an engagement awaits its final acceptance
 * (FA-24). The signed link authenticates the stakeholder personally; the
 * mail carries the customer-visible record only.
 */
class FinalAcceptanceSubmitted extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public FinalAcceptance $finalAcceptance) {}

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
            throw new LogicException('Final acceptance is decided by stakeholders.');
        }

        $engagementName = $this->finalAcceptance->engagement->name;

        return (new MailMessage)
            ->subject(__(':engagement awaits your final acceptance', ['engagement' => $engagementName]))
            ->line(__('Every deliverable of :engagement has been signed off — the engagement now awaits your final acceptance.', [
                'engagement' => $engagementName,
            ]))
            ->action(__('Review the final acceptance'), URL::signedRoute('portal.final-acceptances.show', [
                'finalAcceptance' => $this->finalAcceptance->id,
                'stakeholder' => $notifiable->id,
            ]))
            ->line(__('Please respond by :date. Your decision is recorded immutably against the frozen record — accepting completes the engagement.', [
                'date' => $this->finalAcceptance->respond_by?->toFormattedDateString() ?? '—',
            ]));
    }
}
