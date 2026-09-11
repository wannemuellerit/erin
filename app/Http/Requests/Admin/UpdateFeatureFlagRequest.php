<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeatureFlagRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['required', 'boolean'],
            'rollout_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'conditions' => ['nullable', 'array:roles,company_ids,user_ids,starts_at,ends_at'],
            'conditions.roles' => ['sometimes', 'array'],
            'conditions.roles.*' => ['string', Rule::enum(UserRole::class)],
            'conditions.company_ids' => ['sometimes', 'array'],
            'conditions.company_ids.*' => ['integer', 'distinct', 'exists:companies,id'],
            'conditions.user_ids' => ['sometimes', 'array'],
            'conditions.user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'conditions.starts_at' => ['nullable', 'date'],
            'conditions.ends_at' => ['nullable', 'date', 'after:conditions.starts_at'],
        ];
    }
}
