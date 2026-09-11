<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMaintenanceModeRequest extends FormRequest
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
        $rules = [
            'active' => ['required', 'boolean'],
            'translations' => ['required_if:active,true', 'array'],
            'expected_end_at' => ['nullable', 'date', 'after:now'],
        ];

        foreach (config('app.supported_locales', ['de', 'en']) as $locale) {
            $rules["translations.{$locale}"] = ['required_if:active,true', 'nullable', 'string', 'max:2000'];
        }

        return $rules;
    }
}
