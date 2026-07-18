<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class PushTestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(
        object $notifiable,
        ?Notification $notification = null,
    ): WebPushMessage {
        $locale = $notifiable instanceof User && $notifiable->locale === 'en'
            ? 'en'
            : 'de';

        return (new WebPushMessage)
            ->title($locale === 'en'
                ? 'Erin test notification'
                : 'Erin-Testbenachrichtigung')
            ->body($locale === 'en'
                ? 'Browser push is ready.'
                : 'Browser-Push ist einsatzbereit.')
            ->icon('/favicon.svg')
            ->lang($locale)
            ->tag('erin-push-test')
            ->data([
                'event' => 'system.push_test',
                'url' => route('notification-preferences.edit'),
            ])
            ->action($locale === 'en' ? 'Open' : 'Öffnen', 'open')
            ->options(['TTL' => 300]);
    }
}
