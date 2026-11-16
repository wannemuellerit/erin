<?php

namespace App\Notifications;

use App\Services\Platform\EmailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailTemplatePreviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $key,
        private readonly string $templateLocale,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['mail' => 'notifications'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(EmailTemplateRenderer::class)->render(
            $this->key,
            $this->templateLocale,
            self::sampleVariables($this->templateLocale),
        );

        return (new MailMessage)
            ->subject('[TEST] '.$rendered['subject'])
            ->view('emails.activity-template', [
                'locale' => $this->templateLocale,
                'subject' => $rendered['subject'],
                'bodyHtml' => $rendered['body_html'],
            ])
            ->text('emails.activity-template-text', [
                'locale' => $this->templateLocale,
                'subject' => $rendered['subject'],
                'bodyHtml' => $rendered['body_html'],
                'bodyText' => $rendered['body_text'],
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function sampleVariables(string $locale): array
    {
        return [
            'name' => __('Beispielnutzer', [], $locale),
            'title' => __('Faden-Testbenachrichtigung', [], $locale),
            'message' => __('Diese Vorschau verwendet sichere Beispieldaten.', [], $locale),
            'url' => route('dashboard'),
            'action_label' => __('Faden öffnen', [], $locale),
        ];
    }
}
