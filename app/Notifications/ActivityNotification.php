<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Platform\EmailTemplateRenderer;
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

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

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

    /** @return array<class-string|string, string> */
    public function viaQueues(): array
    {
        return [
            'mail' => 'notifications',
            'database' => 'notifications',
            'broadcast' => 'notifications',
            WebPushChannel::class => 'notifications',
        ];
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
        $templateKey = is_string($payload['event'] ?? null)
            ? (string) $payload['event']
            : 'notification.activity';
        $rendered = app(EmailTemplateRenderer::class)->render($templateKey, $locale, [
            'name' => $notifiable instanceof User ? $notifiable->name : '',
            'title' => (string) ($payload['title'] ?? $this->fallbackTitle($locale)),
            'message' => (string) ($payload['message'] ?? ''),
            'url' => is_string($payload['url'] ?? null) ? $payload['url'] : route('dashboard'),
            'action_label' => __('In Faden ansehen', [], $locale),
        ]);

        $message = (new MailMessage)
            ->subject($rendered['subject'])
            ->tag('erin-'.NotificationPreference::categoryFor((string) ($payload['event'] ?? 'system')))
            ->metadata('notification_delivery_id', (string) ($payload['delivery_id'] ?? ''))
            ->metadata('event', (string) ($payload['event'] ?? 'system'))
            ->greeting(__('Hallo:name,', [
                'name' => $notifiable instanceof User ? " {$notifiable->name}" : '',
            ], $locale));

        if (is_string($payload['url'] ?? null) && $payload['url'] !== '') {
            $message->action(
                __('In Faden ansehen', [], $locale),
                $payload['url'],
            );
        }

        return $message
            ->view('emails.activity-template', [
                'locale' => $locale,
                'subject' => $rendered['subject'],
                'bodyHtml' => $rendered['body_html'],
            ])
            ->text('emails.activity-template-text', [
                'locale' => $locale,
                'subject' => $rendered['subject'],
                'bodyHtml' => $rendered['body_html'],
                'bodyText' => $rendered['body_text'],
            ])
            ->salutation(__('Dein Faden-Team', [], $locale));
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
            ->action(__('Öffnen', [], $locale), 'open')
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
            ? ($translations[$locale] ?? [])
            : [];
        $localized = is_array($localized) ? $localized : [];
        $contentLocale = in_array($locale, ['de', 'en'], true) ? $locale : null;
        $localizedTitle = $localized['title']
            ?? $this->data["title_{$locale}"]
            ?? ($contentLocale !== null ? $this->data["title_{$contentLocale}"] ?? null : null)
            ?? ($contentLocale !== null ? $this->data['title'] ?? null : null)
            ?? $this->fallbackTitle($locale);
        $localizedMessage = $localized['message']
            ?? $this->data["message_{$locale}"]
            ?? ($contentLocale !== null ? $this->data["message_{$contentLocale}"] ?? null : null)
            ?? ($contentLocale !== null ? $this->data['message'] ?? null : null)
            ?? __('In Faden gibt es eine neue Aktualisierung. Öffne die Plattform für Details.', [], $locale);

        return [
            ...Arr::except($this->data, [
                'translations',
                'title_de',
                'title_en',
                'message_de',
                'message_en',
            ]),
            'title' => $localizedTitle,
            'message' => $localizedMessage,
        ];
    }

    private function localeFor(object $notifiable): string
    {
        return $notifiable instanceof User
            && in_array($notifiable->locale, config('app.supported_locales'), true)
                ? $notifiable->locale
                : 'de';
    }

    private function fallbackTitle(string $locale): string
    {
        return __('Neue Aktivität in Faden', [], $locale);
    }
}
