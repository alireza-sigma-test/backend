<?php

// app/Notifications/AccountInvitation.php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountInvitation extends Notification
{
    public function __construct(private readonly string $code) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited')
            ->line('An administrator has invited you to join.')
            ->line('Enter this code to accept the invitation and set your password:')
            ->line($this->code)
            ->line('The code expires in 48 hours.');
    }
}
