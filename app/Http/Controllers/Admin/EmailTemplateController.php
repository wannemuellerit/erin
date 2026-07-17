<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpsertEmailTemplateRequest;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailTemplateController extends AdminController
{
    public function upsert(UpsertEmailTemplateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $key = $validated['key'];

        DB::transaction(function () use ($request, $validated, $key): void {
            $before = $this->templatesFor($key);
            $auditable = null;

            foreach (['de', 'en'] as $locale) {
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

        return back()->with('success', __('Das E-Mail-Template wurde gespeichert.'));
    }

    public function destroy(Request $request, string $key): RedirectResponse
    {
        $templates = EmailTemplate::query()
            ->where('key', $key)
            ->whereIn('locale', ['de', 'en'])
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
                ->whereIn('locale', ['de', 'en'])
                ->delete();
        });

        return back()->with('success', __('Das E-Mail-Template wurde gelöscht.'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function templatesFor(string $key): array
    {
        return EmailTemplate::query()
            ->where('key', $key)
            ->whereIn('locale', ['de', 'en'])
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
