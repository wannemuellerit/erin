<?php

namespace App\Http\Requests\Admin;

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
            'body' => ['required', 'string', 'min:2', 'max:20000'],
            'is_internal' => ['sometimes', 'boolean'],
        ];
    }
}
