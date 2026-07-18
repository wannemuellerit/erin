<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\BillingChangeIntent;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlanStripePrice;
use App\Models\User;
use App\Services\Activity\ActivityRecorder;
use App\Services\Billing\PlanStripePriceRegistry;
use App\Services\Billing\StripeConfigurationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends AdminController
{
    public function index(
        Request $request,
        StripeConfigurationStatus $stripeConfiguration,
    ): Response {
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
                'billing_manual_review' => BillingChangeIntent::query()
                    ->where('status', 'manual_review')
                    ->count(),
                'contract_value_cents' => (int) Company::query()
                    ->whereIn('subscription_status', ['active', 'past_due'])
                    ->join('plans', 'plans.id', '=', 'companies.current_plan_id')
                    ->sum('plans.price_cents'),
            ],
            'stripe_configuration' => $stripeConfiguration->forPlans($plans),
            'billing_manual_reviews' => BillingChangeIntent::query()
                ->with('company:id,name')
                ->where('status', 'manual_review')
                ->latest('updated_at')
                ->limit(20)
                ->get()
                ->map(fn (BillingChangeIntent $intent): array => [
                    'public_id' => $intent->public_id,
                    'resolve_url' => route(
                        'admin.billing.manual-reviews.resolve',
                        $intent->public_id,
                    ),
                    'company_id' => $intent->company_id,
                    'company_name' => $intent->company->name,
                    'change_type' => $intent->change_type,
                    'attempts' => $intent->attempts,
                    'updated_at' => $intent->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    public function updatePlan(
        UpdatePlanRequest $request,
        Plan $plan,
        PlanStripePriceRegistry $priceRegistry,
    ): RedirectResponse {
        $validated = $request->validated();
        $newPriceId = $validated['stripe_price_id'] ?? null;
        if (
            is_string($newPriceId)
            && PlanStripePrice::query()
                ->where('stripe_price_id', $newPriceId)
                ->where('plan_id', '!=', $plan->getKey())
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'stripe_price_id' => __('Diese Stripe-Price-ID gehört historisch bereits zu einem anderen Paket.'),
            ]);
        }

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

        DB::transaction(function () use (
            $plan,
            $priceRegistry,
            $validated,
        ): void {
            $priceRegistry->record($plan, 'admin_previous');
            $plan->update($validated);
            $priceRegistry->record($plan->refresh(), 'admin_update');
        }, 3);

        $this->audit(
            $request,
            'admin.plan.updated',
            $plan,
            $before,
            $plan->only($auditedFields),
        );

        return back()->with('success', __('Das Paket wurde aktualisiert.'));
    }

    public function resolveManualReview(
        Request $request,
        BillingChangeIntent $billingChangeIntent,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['retry', 'close'])],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $action = $validated['action'];
        $reason = $validated['reason'];
        $lock = Cache::lock(
            'stripe-billing-change-company:'
                .$billingChangeIntent->company_id,
            120,
        );
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'action' => __('Dieser Tarifwechsel wird bereits parallel bearbeitet.'),
            ]);
        }

        try {
            DB::transaction(function () use (
                $request,
                $billingChangeIntent,
                $activity,
                $action,
                $reason,
            ): void {
                /** @var BillingChangeIntent $intent */
                $intent = BillingChangeIntent::query()
                    ->lockForUpdate()
                    ->findOrFail($billingChangeIntent->getKey());
                if (
                    $intent->status !== 'manual_review'
                    || $intent->active_company_key
                        !== 'company:'.$intent->company_id
                ) {
                    throw ValidationException::withMessages([
                        'action' => __('Dieser manuelle Prüffall wurde bereits aufgelöst oder besitzt keinen sicheren Firmen-Lock.'),
                    ]);
                }

                $before = [
                    'status' => $intent->status,
                    'active_company_key' => $intent->active_company_key,
                ];
                $intent->forceFill([
                    'status' => $action === 'retry'
                        ? 'reconcile'
                        : 'closed',
                    'active_company_key' => $action === 'retry'
                        ? $intent->active_company_key
                        : null,
                ])->save();
                $event = $action === 'retry'
                    ? 'admin.billing.manual_review.retry_requested'
                    : 'admin.billing.manual_review.closed';
                $this->audit(
                    $request,
                    $event,
                    $intent,
                    $before,
                    [
                        'status' => $intent->status,
                        'active_company_key' => $intent->active_company_key,
                    ],
                    [
                        'intent_public_id' => $intent->public_id,
                        'reason' => $reason,
                    ],
                );

                $actor = $request->user();
                if (! $actor instanceof User) {
                    throw ValidationException::withMessages([
                        'action' => __('Die verantwortliche Person konnte nicht sicher ermittelt werden.'),
                    ]);
                }
                $activity->record(
                    event: $event,
                    actor: $actor,
                    company: $intent->company_id,
                    subject: $intent,
                    payload: [
                        'intent_public_id' => $intent->public_id,
                        'action' => $action,
                        'reason' => $reason,
                    ],
                    visibility: 'platform',
                );
            }, 3);
        } finally {
            $lock->release();
        }

        return back()->with(
            'success',
            $action === 'retry'
                ? __('Der Tarifwechsel wurde für einen sicheren erneuten Stripe-Abgleich freigegeben.')
                : __('Der Tarifwechsel wurde ohne lokale Tarifänderung geschlossen.'),
        );
    }
}
