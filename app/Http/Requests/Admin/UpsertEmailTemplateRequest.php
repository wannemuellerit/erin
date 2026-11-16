<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Services\Platform\EmailTemplateRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SuperAdmin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var list<string> $locales */
        $locales = config('app.supported_locales', ['de', 'en']);
        $translationRules = [
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:200000'],
            'body_text' => ['nullable', 'string', 'max:200000'],
        ];

        $rules = [
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'is_active' => ['required', 'boolean'],
            'translations' => ['required', 'array'],
        ];
        foreach ($locales as $locale) {
            $rules["translations.{$locale}"] = ['required', 'array:subject,body_html,body_text'];
            foreach ($translationRules as $field => $fieldRules) {
                $rules["translations.{$locale}.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'key' => mb_strtolower(trim((string) $this->input('key'))),
        ]);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (config('app.supported_locales', ['de', 'en']) as $locale) {
                if (! is_array($this->input("translations.{$locale}"))) {
                    continue;
                }
                foreach (['subject', 'body_html', 'body_text'] as $field) {
                    $value = $this->input("translations.{$locale}.{$field}");

                    if (! is_string($value)) {
                        continue;
                    }

                    preg_match_all('/{{\s*([a-z_]+)\s*}}/u', $value, $matches);
                    $unknown = array_diff(
                        $matches[1],
                        EmailTemplateRenderer::VARIABLES,
                    );
                    $withoutPlaceholders = preg_replace('/{{\s*[a-z_]+\s*}}/u', '', $value) ?? '';

                    if ($unknown !== [] || preg_match('/{{|}}/', $withoutPlaceholders)) {
                        $validator->errors()->add(
                            "translations.{$locale}.{$field}",
                            __('Das Template enthält eine nicht erlaubte oder fehlerhafte Variable.'),
                        );
                    }
                }
            }
        }];
    }
}
