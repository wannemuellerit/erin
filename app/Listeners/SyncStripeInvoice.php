<?php

namespace App\Listeners;

use App\Models\Company;
use App\Models\CompanyBillingInvoice;
use App\Services\Billing\IntegrationEventGuard;
use App\Services\Billing\StripeEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Events\WebhookReceived;

class SyncStripeInvoice
{
    /** @var list<string> */
    private const EVENTS = [
        'invoice.created',
        'invoice.finalized',
        'invoice.marked_uncollectible',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_succeeded',
        'invoice.updated',
        'invoice.voided',
    ];

    public function __construct(
        private readonly IntegrationEventGuard $events,
        private readonly StripeEnvironment $environment,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        if (
            ! in_array($payload['type'] ?? null, self::EVENTS, true)
            || ! $this->environment->acceptsEventMode($payload['livemode'] ?? null)
        ) {
            return;
        }

        $this->events->once('stripe:invoice', $payload, function (array $payload): void {
            $invoice = $payload['data']['object'] ?? null;
            $eventCreated = $this->nonNegativeInteger($payload['created'] ?? null);
            if (! is_array($invoice) || $eventCreated === null) {
                return;
            }

            $invoiceId = $invoice['id'] ?? null;
            $customerId = $invoice['customer'] ?? null;
            if (
                ! is_string($invoiceId)
                || preg_match('/^in_[A-Za-z0-9_]+$/', $invoiceId) !== 1
                || ! is_string($customerId)
                || preg_match('/^cus_[A-Za-z0-9_]+$/', $customerId) !== 1
            ) {
                return;
            }

            $company = Company::query()->where('stripe_id', $customerId)->first();
            if (! $company instanceof Company) {
                return;
            }

            DB::transaction(function () use ($company, $eventCreated, $invoice, $invoiceId): void {
                $stored = CompanyBillingInvoice::query()
                    ->where('stripe_invoice_id', $invoiceId)
                    ->lockForUpdate()
                    ->first();
                if (
                    $stored instanceof CompanyBillingInvoice
                    && $stored->company_id !== $company->getKey()
                ) {
                    return;
                }
                if (
                    $stored instanceof CompanyBillingInvoice
                    && $stored->stripe_event_created_at > $eventCreated
                ) {
                    return;
                }

                CompanyBillingInvoice::query()->updateOrCreate(
                    ['stripe_invoice_id' => $invoiceId],
                    [
                        'company_id' => $company->getKey(),
                        'number' => $this->nullableString($invoice['number'] ?? null, 255),
                        'status' => $this->status($invoice['status'] ?? null),
                        'currency' => strtoupper($this->currency($invoice['currency'] ?? null)),
                        'subtotal_cents' => $this->amount($invoice['subtotal'] ?? null),
                        'discount_cents' => $this->discount($invoice),
                        'tax_cents' => $this->tax($invoice),
                        'total_cents' => $this->amount($invoice['total'] ?? null),
                        'amount_paid_cents' => $this->amount($invoice['amount_paid'] ?? null),
                        'amount_due_cents' => $this->amount($invoice['amount_due'] ?? null),
                        'billing_reason' => $this->nullableString($invoice['billing_reason'] ?? null, 255),
                        'hosted_invoice_url' => $this->safeUrl($invoice['hosted_invoice_url'] ?? null),
                        'invoice_pdf_url' => $this->safeUrl($invoice['invoice_pdf'] ?? null),
                        'promotion_codes' => $this->promotionCodes($invoice),
                        'customer_tax_ids' => $this->taxIds($invoice['customer_tax_ids'] ?? null),
                        'billing_address' => $this->address($invoice['customer_address'] ?? null),
                        'period_starts_at' => $this->timestamp($invoice['period_start'] ?? null),
                        'period_ends_at' => $this->timestamp($invoice['period_end'] ?? null),
                        'issued_at' => $this->timestamp($invoice['created'] ?? null),
                        'paid_at' => $this->timestamp(data_get($invoice, 'status_transitions.paid_at')),
                        'stripe_event_created_at' => $eventCreated,
                    ],
                );
            }, 3);
        });
    }

    /** @param array<string, mixed> $invoice */
    private function discount(array $invoice): int
    {
        $amounts = $invoice['total_discount_amounts'] ?? [];

        return is_array($amounts)
            ? collect($amounts)->sum(fn (mixed $item): int => is_array($item) ? $this->amount($item['amount'] ?? null) : 0)
            : 0;
    }

    /** @param array<string, mixed> $invoice */
    private function tax(array $invoice): int
    {
        $amounts = $invoice['total_tax_amounts'] ?? [];

        return is_array($amounts)
            ? collect($amounts)->sum(fn (mixed $item): int => is_array($item) ? $this->amount($item['amount'] ?? null) : 0)
            : 0;
    }

    /** @param array<string, mixed> $invoice
     * @return list<string>
     */
    private function promotionCodes(array $invoice): array
    {
        $discounts = $invoice['discounts'] ?? [];
        if (! is_array($discounts)) {
            return [];
        }

        return array_values(collect($discounts)
            ->map(function (mixed $discount): ?string {
                if (! is_array($discount)) {
                    return null;
                }
                $promotion = $discount['promotion_code'] ?? null;
                if (is_array($promotion)) {
                    $promotion = $promotion['code'] ?? $promotion['id'] ?? null;
                }

                return $this->nullableString($promotion, 255);
            })
            ->filter()
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<array{type: string, value: string}> */
    private function taxIds(mixed $taxIds): array
    {
        if (! is_array($taxIds)) {
            return [];
        }

        return array_values(collect($taxIds)->map(function (mixed $taxId): ?array {
            if (! is_array($taxId)) {
                return null;
            }
            $type = $this->nullableString($taxId['type'] ?? null, 80);
            $value = $this->nullableString($taxId['value'] ?? null, 255);

            return $type !== null && $value !== null ? compact('type', 'value') : null;
        })->filter()->values()->all());
    }

    /** @return array<string, string>|null */
    private function address(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }

        $normalized = collect(['line1', 'line2', 'postal_code', 'city', 'state', 'country'])
            ->mapWithKeys(fn (string $key): array => [$key => $this->nullableString($address[$key] ?? null, 255)])
            ->filter()
            ->all();

        return $normalized === [] ? null : $normalized;
    }

    private function status(mixed $value): string
    {
        return in_array($value, ['draft', 'open', 'paid', 'uncollectible', 'void'], true)
            ? $value
            : 'open';
    }

    private function currency(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z]{3}$/', $value) === 1 ? $value : 'eur';
    }

    private function amount(mixed $value): int
    {
        $amount = $this->nonNegativeInteger($value);

        return $amount ?? 0;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        $timestamp = $this->nonNegativeInteger($value);

        return $timestamp === null ? null : now()->setTimestamp($timestamp);
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= $max ? $value : null;
    }

    private function safeUrl(mixed $url): ?string
    {
        return is_string($url)
            && mb_strlen($url) <= 2048
            && filter_var($url, FILTER_VALIDATE_URL)
            && str_starts_with($url, 'https://')
            ? $url
            : null;
    }
}
