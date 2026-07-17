<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

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
        $translationRules = [
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:200000'],
            'body_text' => ['nullable', 'string', 'max:200000'],
        ];

        return [
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'is_active' => ['required', 'boolean'],
            'translations' => ['required', 'array:de,en'],
            'translations.de' => ['required', 'array:subject,body_html,body_text'],
            'translations.en' => ['required', 'array:subject,body_html,body_text'],
            'translations.de.subject' => $translationRules['subject'],
            'translations.de.body_html' => $translationRules['body_html'],
            'translations.de.body_text' => $translationRules['body_text'],
            'translations.en.subject' => $translationRules['subject'],
            'translations.en.body_html' => $translationRules['body_html'],
            'translations.en.body_text' => $translationRules['body_text'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'key' => mb_strtolower(trim((string) $this->input('key'))),
        ]);
    }
}
