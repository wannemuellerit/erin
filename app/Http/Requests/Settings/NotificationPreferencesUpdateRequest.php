<?php

namespace App\Http\Requests\Settings;

use App\Models\NotificationPreference;
use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferencesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [
            'preferences' => [
                'required',
                'array:'.implode(',', NotificationPreference::EVENTS),
            ],
        ];

        foreach (NotificationPreference::EVENTS as $event) {
            $rules["preferences.{$event}"] = [
                'required',
                'array:database_enabled,email_enabled,push_enabled,sms_enabled,whatsapp_enabled',
            ];
            $rules["preferences.{$event}.database_enabled"] = ['required', 'boolean'];
            $rules["preferences.{$event}.email_enabled"] = ['required', 'boolean'];
            $rules["preferences.{$event}.push_enabled"] = ['required', 'boolean'];
            $rules["preferences.{$event}.sms_enabled"] = ['required', 'declined'];
            $rules["preferences.{$event}.whatsapp_enabled"] = ['required', 'declined'];
        }

        return $rules;
    }
}
