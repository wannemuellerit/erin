<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendPlatformNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'submission_key' => ['required', 'string', 'max:100'],
            'audience' => ['required', Rule::in(['all', 'candidate', 'company'])],
            'translations' => ['required', 'array'],
            'url' => ['required', 'string', 'max:500'],
        ];

        foreach (config('app.supported_locales', ['de', 'en']) as $locale) {
            $rules["translations.{$locale}"] = ['required', 'array:title,message'];
            $rules["translations.{$locale}.title"] = ['required', 'string', 'max:120'];
            $rules["translations.{$locale}.message"] = ['required', 'string', 'max:500'];
        }

        return $rules;
    }
}
