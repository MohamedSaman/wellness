<?php

namespace App\Notifications;

use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something on the server needs attention (docs/deploy/CLOUD.md,
 * Monitoring): a backup or its check failed, no backup in 26 hours, the
 * queue or scheduler stopped, the disk is filling up. Mailed to the owners
 * and the alert address (with mail set up; otherwise written to the log by
 * the log mailer), and in the owners' bell in the admin panel.
 */
class HealthAlert extends Notification
{
    public function __construct(
        public readonly string $check,
        public readonly string $title,
        public readonly string $detail,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('['.config('app.name').'] '.$this->title)
            ->line($this->title.' on '.config('app.url').'.')
            ->line($this->detail)
            ->action('Open System health', url('/admin/system-health'))
            ->line('This alert repeats while the problem lasts, at most every '.config('pos.health.alert_every_hours').' hours.');
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title)
            ->body($this->detail)
            ->danger()
            ->getDatabaseMessage();
    }
}
