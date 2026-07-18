<?php

namespace App\Http\Requests\Admin;

use App\Services\Ticketing\SupportAttachmentManager;
use Illuminate\Foundation\Http\FormRequest;

class ReplyToSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformStaff() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...SupportAttachmentManager::validationRules('body'),
            'is_internal' => ['sometimes', 'boolean'],
        ];
    }
}
