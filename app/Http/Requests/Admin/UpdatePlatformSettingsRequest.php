<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePlatformSettingsRequest extends FormRequest
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
            'dashboard_notice' => [
                'required',
                'array:enabled,title_de,title_en,body_de,body_en,url',
            ],
            'dashboard_notice.enabled' => ['required', 'boolean'],
            'dashboard_notice.title_de' => ['required', 'string', 'max:160'],
            'dashboard_notice.title_en' => ['required', 'string', 'max:160'],
            'dashboard_notice.body_de' => ['required', 'string', 'max:2000'],
            'dashboard_notice.body_en' => ['required', 'string', 'max:2000'],
            'dashboard_notice.url' => ['nullable', 'url:http,https', 'max:500'],
            'billing' => [
                'required',
                'array:visa_credit_enabled,visa_credit_price_cents,seat_addon_enabled,seat_addon_price_cents,referral_commission_cents',
            ],
            'billing.visa_credit_enabled' => ['required', 'boolean'],
            'billing.visa_credit_price_cents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'billing.seat_addon_enabled' => ['required', 'boolean'],
            'billing.seat_addon_price_cents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'billing.referral_commission_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                $this->boolean('billing.visa_credit_enabled')
                && ! is_numeric($this->input('billing.visa_credit_price_cents'))
            ) {
                $validator->errors()->add(
                    'billing.visa_credit_price_cents',
                    __('Aktivierte Visa-Zusatzkäufe benötigen einen Preis.'),
                );
            }

            if (
                $this->boolean('billing.seat_addon_enabled')
                && ! is_numeric($this->input('billing.seat_addon_price_cents'))
            ) {
                $validator->errors()->add(
                    'billing.seat_addon_price_cents',
                    __('Aktivierte Zusatzsitze benötigen einen Preis.'),
                );
            }
        });
    }
}
