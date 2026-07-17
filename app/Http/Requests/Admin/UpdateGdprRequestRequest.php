<?php

namespace App\Http\Requests\Admin;

use App\Enums\GdprRequestStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGdprRequestRequest extends FormRequest
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
            'type' => ['required', Rule::in(['export', 'delete'])],
            'status' => ['required', Rule::enum(GdprRequestStatus::class)],
            'reason' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('status') === GdprRequestStatus::Rejected->value,
                ),
                'nullable',
                'string',
                'min:5',
                'max:2000',
            ],
            'due_at' => ['nullable', 'date'],
        ];
    }
}
