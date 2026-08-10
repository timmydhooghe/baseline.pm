<?php

namespace App\Notifications;

use App\Models\Stakeholder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * The stakeholder's magic sign-in link for the customer portal (FA-27).
 * Stakeholders have no password — possession of their mailbox is the
 * credential, so the link is personal, signed and short-lived.
 */
class PortalLoginLink extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * How long a sign-in link stays valid, in minutes.
     */
    public const int EXPIRES_AFTER_MINUTES = 30;

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
            throw new LogicException('Portal sign-in links belong to stakeholders.');
        }

        return (new MailMessage)
            ->subject(__('Your sign-in link for the :customer portal', ['customer' => $notifiable->customer->name]))
            ->line(__('Follow this link to open the portal for the engagements we run with :customer.', [
                'customer' => $notifiable->customer->name,
            ]))
            ->action(__('Sign in to the portal'), URL::temporarySignedRoute('portal.login.consume', now()->addMinutes(self::EXPIRES_AFTER_MINUTES), [
                'stakeholder' => $notifiable->id,
            ]))
            ->line(__('The link is personal and expires in :minutes minutes. If you did not request it, you can safely ignore this email.', [
                'minutes' => self::EXPIRES_AFTER_MINUTES,
            ]));
    }
}
