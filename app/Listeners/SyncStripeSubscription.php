<?php

namespace App\Listeners;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Services\Billing\IntegrationEventGuard;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Events\WebhookHandled;

class SyncStripeSubscription
{
    public function __construct(private readonly IntegrationEventGuard $events) {}

    public function handle(WebhookHandled $event): void
    {
        $type = (string) ($event->payload['type'] ?? '');

        if (! in_array($type, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ], true)) {
            return;
        }

        $this->events->once('stripe:handled', $event->payload, function (array $payload) use ($type): void {
            $data = $payload['data']['object'] ?? [];
            $company = Company::query()->where('stripe_id', $data['customer'] ?? null)->first();

            if ($company === null) {
                return;
            }

            /** @var list<array<string, mixed>> $items */
            $items = is_array($data['items']['data'] ?? null) ? $data['items']['data'] : [];
            $priceIds = collect($items)
                ->map(fn (array $item): ?string => is_string($item['price']['id'] ?? null)
                    ? $item['price']['id']
                    : null)
                ->filter()
                ->values();
            $plan = Plan::query()->whereIn('stripe_price_id', $priceIds)->first();
            $planItem = collect($items)->first(
                fn (array $item): bool => ($item['price']['id'] ?? null) === $plan?->stripe_price_id,
            );
            $status = $type === 'customer.subscription.deleted'
                ? 'canceled'
                : (string) ($data['status'] ?? 'incomplete');
            $periodStart = $data['current_period_start'] ?? $planItem['current_period_start'] ?? null;
            $periodEnd = $data['current_period_end'] ?? $planItem['current_period_end'] ?? null;
            $hasAccess = in_array($status, ['active', 'trialing', 'past_due'], true);

            DB::transaction(function () use (
                $company,
                $data,
                $plan,
                $status,
                $periodStart,
                $periodEnd,
                $hasAccess,
            ): void {
                $company->forceFill([
                    'current_plan_id' => $plan?->getKey() ?? $company->current_plan_id,
                    'pending_plan_id' => $plan?->is($company->pendingPlan) ? null : $company->pending_plan_id,
                    'pending_plan_effective_at' => $plan?->is($company->pendingPlan)
                        ? null
                        : $company->pending_plan_effective_at,
                    'subscription_status' => $status,
                    'subscription_started_at' => $periodStart ? now()->setTimestamp($periodStart) : $company->subscription_started_at,
                    'subscription_renews_at' => $periodEnd ? now()->setTimestamp($periodEnd) : $company->subscription_renews_at,
                    'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
                    'subscription_ends_at' => isset($data['ended_at']) && $data['ended_at']
                        ? now()->setTimestamp($data['ended_at'])
                        : null,
                    'status' => $hasAccess && $company->status === CompanyStatus::Pending
                        ? CompanyStatus::Active
                        : $company->status,
                ])->save();

                DB::table('subscriptions')
                    ->where('stripe_id', $data['id'] ?? '')
                    ->update([
                        'current_period_start' => $periodStart ? now()->setTimestamp($periodStart) : null,
                        'current_period_end' => $periodEnd ? now()->setTimestamp($periodEnd) : null,
                        'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
                        'updated_at' => now(),
                    ]);
            });
        });
    }
}
