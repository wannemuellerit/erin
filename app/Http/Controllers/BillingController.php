<?php

namespace App\Http\Controllers;

use App\Enums\CompanyMemberRole;
use App\Models\Plan;
use App\Services\Billing\EntitlementService;
use App\Services\Companies\CurrentCompany;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Checkout;

class BillingController extends Controller
{
    public function pricing(): Response
    {
        return Inertia::render('Pricing', [
            'plans' => $this->plans(),
        ]);
    }

    public function show(
        Request $request,
        CurrentCompany $currentCompany,
        EntitlementService $entitlements,
    ): Response {
        $company = $currentCompany->forRequest($request);

        return Inertia::render('employer/Billing', [
            'company' => $company->only([
                'id',
                'name',
                'legal_name',
                'email',
                'vat_id',
                'country_code',
                'city',
                'postal_code',
                'address_line1',
                'subscription_status',
                'subscription_renews_at',
                'cancel_at_period_end',
                'pending_plan_effective_at',
            ]),
            'plans' => $this->plans(),
            'entitlements' => $entitlements->summary($company),
            'subscription' => $company->subscriptions()
                ->select(['id', 'type', 'stripe_status', 'stripe_price', 'ends_at'])
                ->first(),
            'add_ons' => [
                'visa_enabled' => (bool) app(PlatformSettings::class)->get('billing.visa_credit_enabled', false)
                    && filled(config('services.stripe.visa_price_id')),
                'seat_enabled' => (bool) app(PlatformSettings::class)->get('billing.seat_addon_enabled', false)
                    && filled(config('services.stripe.seat_price_id')),
                'seat_quantity' => (int) ($company->subscription('default')
                    ?->items()
                    ->where('stripe_price', config('services.stripe.seat_price_id'))
                    ->sum('quantity') ?? 0),
            ],
        ]);
    }

    public function updateDetails(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->forRequest($request);
        $this->assertCanManage($request, $currentCompany);

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:255'],
            'vat_id' => ['nullable', 'string', 'max:32'],
            'country_code' => ['required', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'address_line1' => ['required', 'string', 'max:180'],
        ]);

        $company->update($validated);

        if (filled($company->stripe_id)) {
            $company->syncStripeCustomerDetails();
        }

        return back()->with('success', __('Rechnungsdaten wurden gespeichert.'));
    }

    public function checkout(
        Request $request,
        Plan $plan,
        CurrentCompany $currentCompany,
    ): Checkout|RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertCanManage($request, $currentCompany);

        if (collect([
            $company->legal_name,
            $company->email,
            $company->country_code,
            $company->city,
            $company->postal_code,
            $company->address_line1,
        ])->contains(fn (mixed $value): bool => blank($value))) {
            return back()->withErrors([
                'billing_details' => __('Bitte vervollständige zuerst die Firmen- und Rechnungsdaten.'),
            ]);
        }

        abort_if($plan->is_enterprise, 422, __('Enterprise wird individuell angeboten.'));
        abort_unless($plan->is_active && filled($plan->stripe_price_id), 422, __('Dieser Tarif ist noch nicht für Stripe konfiguriert.'));

        if ($company->subscribed('default')) {
            return back()->with('warning', __('Bitte nutze für bestehende Abonnements den Tarifwechsel.'));
        }

        return $company
            ->newSubscription('default', $plan->stripe_price_id)
            ->withMetadata([
                'company_id' => (string) $company->getKey(),
                'plan_id' => (string) $plan->getKey(),
            ])
            ->collectTaxIds()
            ->checkout([
                'success_url' => route('employer.billing.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('employer.billing'),
                'billing_address_collection' => 'required',
                'allow_promotion_codes' => true,
                'customer_update' => [
                    'address' => 'auto',
                    'name' => 'auto',
                ],
                'metadata' => [
                    'company_id' => (string) $company->getKey(),
                    'plan_id' => (string) $plan->getKey(),
                ],
            ], [
                'name' => $company->legal_name ?: $company->name,
                'email' => $company->email ?: $request->user()?->email,
            ]);
    }

    public function success(Request $request): RedirectResponse
    {
        $request->validate([
            'session_id' => ['required', 'string', 'max:255', 'regex:/^cs_(test_|live_)?[A-Za-z0-9]+$/'],
        ]);

        return redirect()->route('employer.billing')->with(
            'success',
            __('Zahlung eingegangen. Das Portal wird nach Bestätigung des Stripe-Webhooks automatisch freigeschaltet.'),
        );
    }

    public function portal(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->forRequest($request);
        $this->assertCanManage($request, $currentCompany);
        abort_unless(filled($company->stripe_id), 422, __('Für dieses Unternehmen existiert noch kein Stripe-Konto.'));

        return $company->redirectToBillingPortal(route('employer.billing'));
    }

    public function changePlan(
        Request $request,
        Plan $plan,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertCanManage($request, $currentCompany);
        abort_if($plan->is_enterprise || ! $plan->stripe_price_id, 422);

        $subscription = $company->subscription('default');
        abort_if($subscription === null, 422, __('Es besteht noch kein aktives Abonnement.'));

        $company->loadMissing('plan');
        $currentPlan = $company->getRelation('plan');
        $currentPrice = $currentPlan instanceof Plan ? ($currentPlan->price_cents ?? 0) : 0;
        $isUpgrade = ($plan->price_cents ?? PHP_INT_MAX) > $currentPrice;

        if ($isUpgrade) {
            $subscription->swapAndInvoice($plan->stripe_price_id);

            return back()->with('success', __('Das Upgrade wurde sofort beauftragt und anteilig abgerechnet.'));
        }

        $effectiveAt = $company->subscription_renews_at ?? now()->addMonth();
        $stripeSubscription = $subscription->asStripeSubscription();
        $currentItem = $stripeSubscription->items->data[0] ?? null;
        abort_if($currentItem === null, 422, __('Die Stripe-Position konnte nicht geladen werden.'));

        $schedule = $stripeSubscription->schedule
            ? $company->stripe()->subscriptionSchedules->retrieve($stripeSubscription->schedule)
            : $company->stripe()->subscriptionSchedules->create(['from_subscription' => $subscription->stripe_id]);

        $company->stripe()->subscriptionSchedules->update($schedule->id, [
            'end_behavior' => 'release',
            'phases' => [
                [
                    'start_date' => $schedule->current_phase->start_date,
                    'end_date' => $schedule->current_phase->end_date,
                    'items' => [[
                        'price' => $currentItem->price->id,
                        'quantity' => $currentItem->quantity ?? 1,
                    ]],
                    'proration_behavior' => 'none',
                ],
                [
                    'items' => [['price' => $plan->stripe_price_id, 'quantity' => 1]],
                    'proration_behavior' => 'none',
                ],
            ],
        ]);

        $company->update([
            'pending_plan_id' => $plan->getKey(),
            'pending_plan_effective_at' => $effectiveAt,
        ]);

        return back()->with('success', __('Der Downgrade wird zur nächsten Verlängerung wirksam.'));
    }

    public function cancel(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->forRequest($request);
        $this->assertCanManage($request, $currentCompany);
        $company->loadMissing('plan');

        $subscription = $company->subscription('default');
        abort_if($subscription === null, 422, __('Es besteht kein Abonnement.'));

        $renewsAt = $company->subscription_renews_at ?? $subscription->currentPeriodEnd();
        abort_if($renewsAt === null, 422, __('Das Laufzeitende konnte nicht ermittelt werden.'));

        $currentPlan = $company->getRelation('plan');
        $termMonths = $currentPlan instanceof Plan ? ($currentPlan->term_months ?? 1) : 1;
        $cancelAt = now()->lte($renewsAt->copy()->subDays(14))
            ? $renewsAt
            : $renewsAt->copy()->addMonths($termMonths);

        $subscription->cancelAt($cancelAt);
        $company->update(['cancel_at_period_end' => true, 'subscription_ends_at' => $cancelAt]);

        return back()->with('success', __('Die Kündigung ist zum :date vorgemerkt.', [
            'date' => $cancelAt->format('d.m.Y'),
        ]));
    }

    public function buyVisaCredits(
        Request $request,
        CurrentCompany $currentCompany,
        PlatformSettings $settings,
    ): Checkout {
        $company = $currentCompany->forRequest($request);
        $this->assertCanManage($request, $currentCompany);
        $validated = $request->validate(['credits' => ['required', 'integer', 'min:1', 'max:100']]);

        $priceId = config('services.stripe.visa_price_id');
        abort_unless($settings->get('billing.visa_credit_enabled', false) && filled($priceId), 422, __('Der Zusatzkauf ist noch nicht konfiguriert.'));

        return Checkout::customer($company)->create(
            [$priceId => $validated['credits']],
            [
                'success_url' => route('employer.billing').'?purchase=success',
                'cancel_url' => route('employer.billing'),
                'invoice_creation' => ['enabled' => true],
                'metadata' => [
                    'purchase_type' => 'visa_credits',
                    'company_id' => (string) $company->getKey(),
                    'credits' => (string) $validated['credits'],
                ],
            ],
        );
    }

    public function addSeats(
        Request $request,
        CurrentCompany $currentCompany,
        PlatformSettings $settings,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertCanManage($request, $currentCompany);
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $priceId = (string) config('services.stripe.seat_price_id');

        abort_unless(
            $settings->get('billing.seat_addon_enabled', false) && $priceId !== '',
            422,
            __('Zusätzliche Recruiter-Sitze sind noch nicht konfiguriert.'),
        );

        $subscription = $company->subscription('default');
        abort_if($subscription === null, 422, __('Es besteht noch kein aktives Abonnement.'));

        $quantity = (int) $validated['quantity'];

        if ($subscription->items()->where('stripe_price', $priceId)->exists()) {
            $subscription->incrementAndInvoice($quantity, $priceId);
        } else {
            $subscription->addPriceAndInvoice($priceId, $quantity);
        }

        return back()->with('success', trans_choice(
            ':count zusätzlicher Recruiter-Sitz wurde anteilig abgerechnet.|:count zusätzliche Recruiter-Sitze wurden anteilig abgerechnet.',
            $quantity,
            ['count' => $quantity],
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plans(): array
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderByRaw('price_cents is null')
            ->orderBy('price_cents')
            ->get()
            ->map(fn (Plan $plan): array => [
                ...$plan->only([
                    'id',
                    'slug',
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
                    'is_enterprise',
                    'features',
                ]),
                'checkout_available' => ! $plan->is_enterprise && filled($plan->stripe_price_id),
            ])
            ->all();
    }

    private function assertCanManage(Request $request, CurrentCompany $currentCompany): void
    {
        abort_unless(
            in_array($currentCompany->membership($request)->role, [
                CompanyMemberRole::Owner,
                CompanyMemberRole::Admin,
            ], true),
            403,
        );
    }
}
