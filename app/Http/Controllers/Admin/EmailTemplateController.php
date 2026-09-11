<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpsertEmailTemplateRequest;
use App\Models\EmailTemplate;
use App\Notifications\EmailTemplatePreviewNotification;
use App\Services\Platform\EmailTemplateRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class EmailTemplateController extends AdminController
{
    public function upsert(
        UpsertEmailTemplateRequest $request,
        EmailTemplateRenderer $renderer,
    ): RedirectResponse {
        $validated = $request->validated();
        $key = $validated['key'];

        DB::transaction(function () use ($request, $validated, $key): void {
            $before = $this->templatesFor($key);
            $auditable = null;

            foreach (config('app.supported_locales', ['de', 'en']) as $locale) {
                $translation = $validated['translations'][$locale];

                $template = EmailTemplate::query()->updateOrCreate(
                    [
                        'key' => $key,
                        'locale' => $locale,
                    ],
                    [
                        'subject' => $translation['subject'],
                        'body_html' => $translation['body_html'],
                        'body_text' => $translation['body_text'] ?? null,
                        'is_active' => $validated['is_active'],
                        'updated_by' => $request->user()?->getKey(),
                    ],
                );

                $auditable ??= $template;
            }

            $this->audit(
                $request,
                'admin.email_template.upserted',
                $auditable,
                $before,
                $this->templatesFor($key),
                ['key' => $key],
            );
        });
        $renderer->forget($key);

        return back()->with('success', __('Das E-Mail-Template wurde gespeichert.'));
    }

    public function destroy(
        Request $request,
        string $key,
        EmailTemplateRenderer $renderer,
    ): RedirectResponse {
        $templates = EmailTemplate::query()
            ->where('key', $key)
            ->whereIn('locale', config('app.supported_locales', ['de', 'en']))
            ->get();

        abort_if($templates->isEmpty(), 404);

        $before = $this->templatesFor($key);
        $auditable = $templates->first();

        DB::transaction(function () use ($request, $key, $before, $auditable): void {
            $this->audit(
                $request,
                'admin.email_template.deleted',
                $auditable,
                $before,
                metadata: ['key' => $key],
            );

            EmailTemplate::query()
                ->where('key', $key)
                ->whereIn('locale', config('app.supported_locales', ['de', 'en']))
                ->delete();
        });
        $renderer->forget($key);

        return back()->with('success', __('Das E-Mail-Template wurde gelöscht.'));
    }

    public function preview(
        Request $request,
        string $key,
        EmailTemplateRenderer $renderer,
    ): Response {
        $validated = $request->validate([
            'locale' => ['required', Rule::in(config('app.supported_locales', ['de', 'en']))],
        ]);
        abort_unless(EmailTemplate::query()->where('key', $key)->exists(), 404);
        $locale = (string) $validated['locale'];
        $rendered = $renderer->render(
            $key,
            $locale,
            EmailTemplatePreviewNotification::sampleVariables($locale),
        );
        $this->audit($request, 'admin.email_template.previewed', metadata: [
            'key' => $key,
            'locale' => $locale,
            'fallback_used' => $rendered['fallback_used'],
        ]);

        return response()->view('emails.activity-template', [
            'locale' => $locale,
            'subject' => $rendered['subject'],
            'bodyHtml' => $rendered['body_html'],
        ], 200, [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function sendTest(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/', 'exists:email_templates,key'],
            'locale' => ['required', Rule::in(config('app.supported_locales', ['de', 'en']))],
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        Notification::route('mail', $validated['email'])
            ->notify(new EmailTemplatePreviewNotification(
                $validated['key'],
                $validated['locale'],
            ));
        $this->audit($request, 'admin.email_template.test_queued', metadata: [
            'key' => $validated['key'],
            'locale' => $validated['locale'],
            'recipient_is_actor' => hash_equals(
                mb_strtolower((string) $request->user()?->email),
                mb_strtolower((string) $validated['email']),
            ),
        ]);

        return back()->with('success', __('Die Test-E-Mail wurde in die Queue gestellt.'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function templatesFor(string $key): array
    {
        return EmailTemplate::query()
            ->where('key', $key)
            ->whereIn('locale', config('app.supported_locales', ['de', 'en']))
            ->orderBy('locale')
            ->get()
            ->mapWithKeys(fn (EmailTemplate $template): array => [
                $template->locale => [
                    'subject' => $template->subject,
                    'body_html' => $template->body_html,
                    'body_text' => $template->body_text,
                    'is_active' => $template->is_active,
                    'updated_by' => $template->updated_by,
                ],
            ])
            ->all();
    }
}
