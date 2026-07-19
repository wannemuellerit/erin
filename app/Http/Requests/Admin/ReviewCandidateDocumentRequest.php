<?php

namespace App\Http\Requests\Admin;

use App\Enums\CandidateDocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewCandidateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
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
                    CandidateDocumentStatus::InReview->value,
                    CandidateDocumentStatus::Verified->value,
                    CandidateDocumentStatus::Rejected->value,
                ]),
            ],
            'rejection_reason' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('status') === CandidateDocumentStatus::Rejected->value,
                ),
                'nullable',
                'string',
                'min:5',
                'max:2000',
            ],
        ];
    }
}
