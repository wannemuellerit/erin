<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\Company;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends AdminController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'subscription_status' => ['nullable', 'string', 'max:40'],
            'plan_id' => ['nullable', 'integer'],
        ]);

        $companies = Company::query()
            ->select([
                'id',
                'current_plan_id',
                'name',
                'stripe_id',
                'subscription_status',
                'subscription_started_at',
                'subscription_renews_at',
                'cancel_at_period_end',
                'subscription_ends_at',
            ])
            ->with([
                'plan:id,slug,name,price_cents,currency,term_months',
                'usagePeriods' => fn ($query) => $query->latest('starts_at')->limit(1),
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where('name', 'like', "%{$search}%");
            })
            ->when(
                $filters['subscription_status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('subscription_status', $status),
            )
            ->when(
                $filters['plan_id'] ?? null,
                fn (Builder $query, int $planId): Builder => $query->where('current_plan_id', $planId),
            )
            ->orderByDesc('subscription_renews_at')
            ->paginate(20)
            ->withQueryString();

        $plans = Plan::query()
            ->withCount('companies')
            ->orderByRaw('price_cents is null')
            ->orderBy('price_cents')
            ->get();

        return Inertia::render('admin/Billing', [
            'companies' => $companies,
            'plans' => $plans,
            'filters' => $filters,
            'summary' => [
                'active' => Company::query()->where('subscription_status', 'active')->count(),
                'past_due' => Company::query()->where('subscription_status', 'past_due')->count(),
                'cancelling' => Company::query()->where('cancel_at_period_end', true)->count(),
                'contract_value_cents' => (int) Company::query()
                    ->whereIn('subscription_status', ['active', 'past_due'])
                    ->join('plans', 'plans.id', '=', 'companies.current_plan_id')
                    ->sum('plans.price_cents'),
            ],
        ]);
    }

    public function updatePlan(UpdatePlanRequest $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validated();
        $newPriceId = $validated['stripe_price_id'] ?? null;

        if (
            (int) ($validated['price_cents'] ?? 0) !== (int) ($plan->price_cents ?? 0)
            && filled($plan->stripe_price_id)
            && $newPriceId === $plan->stripe_price_id
        ) {
            throw ValidationException::withMessages([
                'stripe_price_id' => __('Stripe-Preise sind unveränderlich. Hinterlege für den neuen Betrag eine neue Price-ID.'),
            ]);
        }

        $auditedFields = [
            'name',
            'description',
            'price_cents',
            'currency',
            'term_months',
            'active_jobs_limit',
            'seat_limit',
            'ai_credits_monthly',
            'job_boosts_per_term',
            'visa_credits_per_term',
            'is_active',
            'stripe_product_id',
            'stripe_price_id',
            'features',
        ];
        $before = $plan->only($auditedFields);

        $plan->update($validated);

        $this->audit(
            $request,
            'admin.plan.updated',
            $plan,
            $before,
            $plan->only($auditedFields),
        );

        return back()->with('success', __('Das Paket wurde aktualisiert.'));
    }
}
