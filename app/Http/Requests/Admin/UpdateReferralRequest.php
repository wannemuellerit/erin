<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReferralStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReferralRequest extends FormRequest
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
            'status' => [
                'required',
                Rule::in([
                    ReferralStatus::Approved->value,
                    ReferralStatus::Paid->value,
                    ReferralStatus::Rejected->value,
                ]),
            ],
            'reason' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('status') === ReferralStatus::Rejected->value,
                ),
                'nullable',
                'string',
                'min:5',
                'max:1000',
            ],
        ];
    }
}
