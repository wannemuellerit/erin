<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
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
        /** @var Plan|null $plan */
        $plan = $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['required', 'string', 'size:3'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'active_jobs_limit' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'seat_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'ai_credits_monthly' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'job_boosts_per_term' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'visa_credits_per_term' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
            'stripe_product_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('plans', 'stripe_product_id')->ignore($plan?->getKey()),
            ],
            'stripe_price_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('plans', 'stripe_price_id')->ignore($plan?->getKey()),
            ],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) $this->input('currency', 'EUR'))]);
    }
}
