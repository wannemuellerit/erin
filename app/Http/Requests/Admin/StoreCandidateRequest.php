<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SuperAdmin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'temporary_password' => ['required', 'string', Password::min(12)->letters()->numbers()->symbols()],
            'email_verified' => ['boolean'],
            'locale' => ['required', 'in:de,en'],
            'current_country_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'current_city' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'occupation_id' => ['nullable', 'exists:occupations,id'],
            'current_position' => ['nullable', 'string', 'max:180'],
            'desired_position' => ['nullable', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
