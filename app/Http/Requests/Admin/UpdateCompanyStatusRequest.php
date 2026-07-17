<?php

namespace App\Http\Requests\Admin;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(CompanyStatus::class)],
            'reason' => [
                Rule::requiredIf(fn (): bool => in_array(
                    $this->input('status'),
                    [CompanyStatus::Suspended->value, CompanyStatus::Blocked->value],
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
