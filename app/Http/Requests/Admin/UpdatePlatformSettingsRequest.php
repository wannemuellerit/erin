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
            'uploads' => ['required', 'array:max_file_size_mb,user_quota_mb'],
            'uploads.max_file_size_mb' => ['required', 'integer', 'min:1', 'max:100'],
            'uploads.user_quota_mb' => ['required', 'integer', 'min:10', 'max:102400'],
            'retention' => [
                'sometimes',
                'array:rejected_document_days,message_attachment_days,support_attachment_days,audit_log_days,orphan_grace_hours',
            ],
            'retention.rejected_document_days' => ['required_with:retention', 'integer', 'min:0', 'max:3650'],
            'retention.message_attachment_days' => ['required_with:retention', 'integer', 'min:0', 'max:3650'],
            'retention.support_attachment_days' => ['required_with:retention', 'integer', 'min:0', 'max:3650'],
            'retention.audit_log_days' => ['required_with:retention', 'integer', 'min:0', 'max:3650'],
            'retention.orphan_grace_hours' => ['required_with:retention', 'integer', 'min:1', 'max:720'],
            'dashboard_ad' => [
                'required',
                'array:enabled,campaign_id,campaign_name,audience,title_de,title_en,body_de,body_en,cta_label_de,cta_label_en,url,starts_at,ends_at',
            ],
            'dashboard_ad.enabled' => ['required', 'boolean'],
            'dashboard_ad.campaign_id' => ['nullable', 'integer', 'exists:ad_campaigns,id'],
            'dashboard_ad.campaign_name' => ['nullable', 'string', 'max:160'],
            'dashboard_ad.audience' => ['required', 'in:all,candidate,company'],
            'dashboard_ad.title_de' => ['nullable', 'string', 'max:160'],
            'dashboard_ad.title_en' => ['nullable', 'string', 'max:160'],
            'dashboard_ad.body_de' => ['nullable', 'string', 'max:2000'],
            'dashboard_ad.body_en' => ['nullable', 'string', 'max:2000'],
            'dashboard_ad.cta_label_de' => ['nullable', 'string', 'max:80'],
            'dashboard_ad.cta_label_en' => ['nullable', 'string', 'max:80'],
            'dashboard_ad.url' => ['nullable', 'url:http,https', 'max:500'],
            'dashboard_ad.starts_at' => ['nullable', 'date'],
            'dashboard_ad.ends_at' => ['nullable', 'date', 'after:dashboard_ad.starts_at'],
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

            if ($this->boolean('dashboard_ad.enabled')) {
                foreach (['de', 'en'] as $locale) {
                    if (! filled($this->input("dashboard_ad.title_{$locale}"))) {
                        $validator->errors()->add(
                            "dashboard_ad.title_{$locale}",
                            __('Aktive Anzeigen benötigen einen Titel in beiden Sprachen.'),
                        );
                    }
                    if (! filled($this->input("dashboard_ad.body_{$locale}"))) {
                        $validator->errors()->add(
                            "dashboard_ad.body_{$locale}",
                            __('Aktive Anzeigen benötigen einen Text in beiden Sprachen.'),
                        );
                    }
                }
            }
        });
    }
}
