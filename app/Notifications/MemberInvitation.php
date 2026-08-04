<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class MemberInvitation extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation) {}

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
        $organizationName = $this->invitation->organization->name;
        $inviterName = $this->invitation->inviter->name ?? $organizationName;

        return (new MailMessage)
            ->subject(__(':organization invited you to Baseline', ['organization' => $organizationName]))
            ->line(__(':inviter invited you to join :organization on Baseline as :role.', [
                'inviter' => $inviterName,
                'organization' => $organizationName,
                'role' => $this->invitation->role->label(),
            ]))
            ->action(__('Accept invitation'), route('invitations.show', ['token' => $this->invitation->token]))
            ->line(__('This invitation expires on :date. If you were not expecting it, you can ignore this email.', [
                'date' => $this->invitation->expires_at->toFormattedDateString(),
            ]));
    }
}
