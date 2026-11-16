# ADR 0005: Provider-independent referral payouts

## Status

Accepted. Production activation remains disabled until a payout-provider contract, DPA, webhook secret and accounting approval are present.

## Decision

Erin owns the payout state machine and immutable idempotency key. A small `PayoutProvider` port isolates Stripe Connect today and allows another regulated provider later. Erin stores only an encrypted provider account identifier, never bank credentials.

The lifecycle is `awaiting_account → manual_review|approved → submitted → paid|failed`. A referral can have exactly one payout intent. Provider callbacks are signed and deduplicated, and a scheduled reconciliation process repairs missing callbacks. Suspicious self-referrals, shared payout accounts, high amounts and velocity require a documented admin decision. Accounting receives a pseudonymous CSV export keyed by payout and referral IDs.

## Consequences

Referral status can no longer be set to `paid` manually. Only the provider callback, synchronous provider confirmation or reconciliation may do that. Provider downtime is isolated to the payments queue and safe retries reuse the same idempotency key.
