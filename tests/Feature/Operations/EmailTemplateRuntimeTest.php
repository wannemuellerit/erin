<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Notifications\EmailTemplatePreviewNotification;
use App\Services\Platform\EmailTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('renders active localized templates with escaped variables and safe links', function () {
    config()->set('app.url', 'https://erin.example');
    EmailTemplate::query()->create([
        'key' => 'message.received',
        'locale' => 'de',
        'subject' => '{{ title }}',
        'body_html' => '<p onclick="alert(1)">Hallo {{ name }}</p><p>{{ message }}</p><a style="color:red" href="{{ url }}">{{ action_label }}</a>',
        'body_text' => "Hallo {{ name }}\n{{ message }}\n{{ url }}",
        'is_active' => true,
    ]);
    app(EmailTemplateRenderer::class)->forget('message.received');

    $rendered = app(EmailTemplateRenderer::class)->render('message.received', 'de', [
        'name' => '<script>alert(1)</script>',
        'title' => "Neue Nachricht\r\nBcc: attacker@example.org",
        'message' => '<strong>private</strong>',
        'url' => 'https://erin.example/messages/42',
        'action_label' => 'Öffnen',
    ]);

    expect($rendered)
        ->fallback_used->toBeFalse()
        ->and($rendered['subject'])->not->toContain("\r", "\n")
        ->and($rendered['body_html'])
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('href="https://erin.example/messages/42"')
        ->not->toContain('onclick', 'style=', '<script>');
});

it('falls back safely when an active database template is malformed', function () {
    EmailTemplate::query()->create([
        'key' => 'message.received',
        'locale' => 'en',
        'subject' => '{{ forbidden_secret }}',
        'body_html' => '<p>{{ message }}</p>',
        'body_text' => '{{ message }}',
        'is_active' => true,
    ]);
    app(EmailTemplateRenderer::class)->forget('message.received');

    $rendered = app(EmailTemplateRenderer::class)->render('message.received', 'en', [
        'name' => 'Ada',
        'title' => 'New message',
        'message' => 'You have a message.',
        'url' => route('dashboard'),
        'action_label' => 'Open',
    ]);

    expect($rendered)
        ->fallback_used->toBeTrue()
        ->subject->toBe('New message')
        ->and($rendered['body_text'])->toContain('You have a message.');
});

it('rejects unknown template variables before persisting and uses templates in mail notifications', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $payload = [
        'key' => 'message.received',
        'is_active' => true,
        'translations' => [
            'de' => [
                'subject' => '{{ title }}',
                'body_html' => '<p>{{ unknown }}</p>',
                'body_text' => '{{ message }}',
            ],
            'en' => [
                'subject' => '{{ title }}',
                'body_html' => '<p>{{ message }}</p>',
                'body_text' => '{{ message }}',
            ],
        ],
    ];
    foreach (array_diff(config('app.supported_locales'), ['de', 'en']) as $locale) {
        $payload['translations'][$locale] = [
            'subject' => '{{ title }}',
            'body_html' => '<p>{{ message }}</p>',
            'body_text' => '{{ message }}',
        ];
    }

    $this->actingAs($admin)
        ->post(route('admin.email-templates.upsert'), $payload)
        ->assertSessionHasErrors('translations.de.body_html');

    $payload['translations']['de']['body_html'] = '<p>{{ message }}</p>';
    $this->actingAs($admin)
        ->post(route('admin.email-templates.upsert'), $payload)
        ->assertRedirect();

    $user = User::factory()->create(['name' => 'Emil', 'locale' => 'de']);
    $mail = (new ActivityNotification([
        'event' => 'message.received',
        'title' => 'Neue Nachricht',
        'message' => 'Hallo aus Faden',
        'url' => route('dashboard'),
    ]))->toMail($user);

    expect($mail->view)->toMatchArray([
        'html' => 'emails.activity-template',
        'text' => 'emails.activity-template-text',
    ])
        ->and($mail->viewData['bodyHtml'])->toContain('Hallo aus Faden');
});

it('requires every active locale and uses a localized safe fallback for legacy gaps', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($admin)
        ->post(route('admin.email-templates.upsert'), [
            'key' => 'message.received',
            'is_active' => true,
            'translations' => [
                'de' => [
                    'subject' => '{{ title }}',
                    'body_html' => '<p>{{ message }}</p>',
                    'body_text' => '{{ message }}',
                ],
                'en' => [
                    'subject' => '{{ title }}',
                    'body_html' => '<p>{{ message }}</p>',
                    'body_text' => '{{ message }}',
                ],
            ],
        ])
        ->assertSessionHasErrors('translations.pl');

    $rendered = app(EmailTemplateRenderer::class)->render('legacy.only-de-en', 'pl', [
        'name' => 'Ada',
        'title' => __('Faden-Testbenachrichtigung', [], 'pl'),
        'message' => __('Browser-Push ist einsatzbereit.', [], 'pl'),
        'url' => route('dashboard'),
        'action_label' => __('Faden öffnen', [], 'pl'),
    ]);
    $expectedGreeting = str_replace(
        '{{ name }}',
        'Ada',
        __('Hallo {{ name }},', [], 'pl'),
    );

    expect($rendered)
        ->fallback_used->toBeTrue()
        ->and($rendered['body_text'])->toContain($expectedGreeting)
        ->not->toContain('Hello Ada');
});

it('audits safe previews and queues test deliveries without exposing content in the audit log', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    foreach (['de', 'en'] as $locale) {
        EmailTemplate::query()->create([
            'key' => 'application.accepted',
            'locale' => $locale,
            'subject' => '{{ title }}',
            'body_html' => '<p>{{ message }}</p>',
            'body_text' => '{{ message }}',
            'is_active' => true,
        ]);
    }
    app(EmailTemplateRenderer::class)->forget('application.accepted');

    $this->actingAs($admin)
        ->get(route('admin.email-templates.preview', [
            'key' => 'application.accepted',
            'locale' => 'de',
        ]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy')
        ->assertSee('Diese Vorschau verwendet sichere Beispieldaten.');

    $this->actingAs($admin)
        ->post(route('admin.email-templates.test'), [
            'key' => 'application.accepted',
            'locale' => 'en',
            'email' => $admin->email,
        ])
        ->assertRedirect();

    Notification::assertSentOnDemand(EmailTemplatePreviewNotification::class);
    $events = AuditLog::query()
        ->whereIn('event', [
            'admin.email_template.previewed',
            'admin.email_template.test_queued',
        ])
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events->toJson())->not->toContain('Diese Vorschau');
});
