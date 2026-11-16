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
        $locale = $notifiable instanceof User
            && in_array($notifiable->locale, config('app.supported_locales'), true)
                ? $notifiable->locale
                : 'de';

        return (new WebPushMessage)
            ->title(__('Faden-Testbenachrichtigung', [], $locale))
            ->body(__('Browser-Push ist einsatzbereit.', [], $locale))
            ->icon('/favicon.svg')
            ->lang($locale)
            ->tag('erin-push-test')
            ->data([
                'event' => 'system.push_test',
                'url' => route('notification-preferences.edit'),
            ])
            ->action(__('Öffnen', [], $locale), 'open')
            ->options(['TTL' => 300]);
    }
}
