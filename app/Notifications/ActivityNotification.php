<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class ActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    /**
     * @return list<class-string|string>
     */
    public function via(object $notifiable): array
    {
        $preference = $this->preferenceFor($notifiable);
        $channels = [];

        if ($preference['database_enabled']) {
            $channels[] = 'database';
            $channels[] = 'broadcast';
        }

        if ($preference['email_enabled']) {
            $channels[] = 'mail';
        }

        if ($preference['push_enabled']) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->localizedData($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->localizedData($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payload = $this->localizedData($notifiable);
        $locale = $this->localeFor($notifiable);
        $name = $notifiable instanceof User ? $notifiable->name : null;
        $message = (new MailMessage)
            ->subject((string) ($payload['title'] ?? $this->fallbackTitle($locale)))
            ->greeting($locale === 'en'
                ? sprintf('Hello%s,', $name ? " {$name}" : '')
                : sprintf('Hallo%s,', $name ? " {$name}" : ''))
            ->line((string) ($payload['message'] ?? ''));

        if (is_string($payload['url'] ?? null) && $payload['url'] !== '') {
            $message->action(
                $locale === 'en' ? 'View in Erin' : 'In Erin ansehen',
                $payload['url'],
            );
        }

        return $message->salutation($locale === 'en'
            ? 'Your Erin team'
            : 'Dein Erin-Team');
    }

    public function toWebPush(
        object $notifiable,
        ?Notification $notification = null,
    ): WebPushMessage {
        $payload = $this->localizedData($notifiable);
        $locale = $this->localeFor($notifiable);
        $event = (string) ($payload['event'] ?? 'system');

        return (new WebPushMessage)
            ->title((string) ($payload['title'] ?? $this->fallbackTitle($locale)))
            ->body((string) ($payload['message'] ?? ''))
            ->icon('/favicon.svg')
            ->lang($locale)
            ->tag('erin-'.NotificationPreference::categoryFor($event))
            ->data([
                'event' => $event,
                'url' => is_string($payload['url'] ?? null) ? $payload['url'] : route('dashboard'),
            ])
            ->action($locale === 'en' ? 'Open' : 'Öffnen', 'open')
            ->options(['TTL' => 3600]);
    }

    /**
     * @return array{
     *     database_enabled: bool,
     *     email_enabled: bool,
     *     push_enabled: bool,
     *     sms_enabled: bool,
     *     whatsapp_enabled: bool
     * }
     */
    private function preferenceFor(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return NotificationPreference::DEFAULTS;
        }

        $event = (string) ($this->data['event'] ?? 'system');
        $category = NotificationPreference::categoryFor($event);
        $keys = array_values(array_unique([$event, $category, 'default']));
        $preferences = $notifiable->notificationPreferences()
            ->whereIn('event', $keys)
            ->get()
            ->keyBy('event');
        $preference = collect($keys)
            ->map(fn (string $key): ?NotificationPreference => $preferences->get($key))
            ->first(fn (?NotificationPreference $candidate): bool => $candidate !== null);

        if ($preference === null) {
            return NotificationPreference::DEFAULTS;
        }

        return [
            'database_enabled' => $preference->database_enabled,
            'email_enabled' => $preference->email_enabled,
            'push_enabled' => $preference->push_enabled,
            'sms_enabled' => false,
            'whatsapp_enabled' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function localizedData(object $notifiable): array
    {
        $locale = $this->localeFor($notifiable);
        $translations = $this->data['translations'] ?? null;
        $localized = is_array($translations)
            && is_array($translations[$locale] ?? null)
                ? $translations[$locale]
                : [];

        return [
            ...Arr::except($this->data, [
                'translations',
                'title_de',
                'title_en',
                'message_de',
                'message_en',
            ]),
            'title' => $localized['title']
                ?? $this->data["title_{$locale}"]
                ?? $this->data['title']
                ?? $this->fallbackTitle($locale),
            'message' => $localized['message']
                ?? $this->data["message_{$locale}"]
                ?? $this->data['message']
                ?? '',
        ];
    }

    /**
     * @return 'de'|'en'
     */
    private function localeFor(object $notifiable): string
    {
        return $notifiable instanceof User && $notifiable->locale === 'en'
            ? 'en'
            : 'de';
    }

    /**
     * @param  'de'|'en'  $locale
     */
    private function fallbackTitle(string $locale): string
    {
        return $locale === 'en' ? 'News from Erin' : 'Neuigkeiten von Erin';
    }
}
