<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class WorkspaceNotification extends Notification
{
    public function __construct(public array $event) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->event;
    }
}
