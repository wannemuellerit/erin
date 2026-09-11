# ADR 0006: Country matrix and governed partner platform

## Status

Accepted for implementation. Every external country/service combination remains disabled until its legal, security and commercial approvals are recorded.

## Decision

Country expansion is controlled twice: a versioned `country_service_rules` decision and a 100% country/operation feature flag must both be active. Recruiting, employment contracts, billing, visa, relocation and each partner vertical are independent decisions. A newer disabled or unapproved rule immediately overrides an older approval.

The partner platform uses a common case, catalog and provider-port model for language courses, recognition/translation, insurance, housing/travel, tax/bank, connectivity and payroll. Partner members can see only cases assigned to their operational organization and only while purpose-bound consent is active. A contract, DPA and legal approval are mandatory; blocking an organization revokes memberships and active sharing immediately.

Case artifacts are encrypted and versioned. Document grants expire and are revocable. Provider receipts are signed and idempotent. Employer timelines contain only explicitly marked process events. Health details, credentials, PIN/TAN and activation codes are rejected at the write boundary. Recognition decisions require a named human authority, and language-course evidence never changes language verification automatically.

## Activation checklist

- Legal basis and current rule version approved per country and operation.
- Partner contract, DPA, data fields, retention and support escalation approved.
- Provider secret and reconciliation ownership configured.
- Country feature flag enabled only after smoke, isolation and outage tests.
- Logos and co-branding used only after written approval.
