<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(UserStatus::class)],
            'reason' => [
                Rule::requiredIf(fn (): bool => in_array(
                    $this->input('status'),
                    [UserStatus::Suspended->value, UserStatus::Blocked->value],
                    true,
                )),
                'nullable',
                'string',
                'min:5',
                'max:1000',
            ],
        ];
    }
}
