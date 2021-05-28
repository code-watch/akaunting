<?php

namespace App\Notifications\Common;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ExportCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    protected $download_url;

    /**
     * Create a notification instance.
     *
     * @param  string  $download_url
     */
    public function __construct($download_url)
    {
        $this->download_url = $download_url;

        $this->onQueue('notifications');
    }

    /**
     * Get the notification's channels.
     *
     * @param  mixed  $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->from(config('mail.from.address'), setting('company.name'))
            ->subject(trans('notifications.export.completed.subject'))
            ->line(trans('notifications.export.completed.description'))
            ->action(trans('general.download'), $this->download_url);
    }
}
