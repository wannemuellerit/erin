# Localization release process

Erin supports the locale identifiers `de`, `en`, `pl`, `ro`, `hr`, `es` and `pt` end to end: account selection, formatting, email-template variants, chatbot/translation targets and stable fallback catalogs. German and English are reviewed production catalogs. Polish, Romanian, Croatian, Spanish and Portuguese have complete machine-translated draft catalogs; they remain release candidates until the native and legal reviews below are recorded.

A locale release requires all of the following:

1. Translate every master catalog, backend validation catalog, email/notification template and legal page using the shared recruiting/visa/relocation glossary.
2. Run `npm run i18n:check`, placeholder-parity checks, TypeScript, lint and the complete candidate-onboarding Playwright flow for every locale. Keep the generated full-page screenshots as release evidence.
3. Obtain a native-speaker review for terminology and tone and a legal review for privacy, terms, consent, insurance, tax/bank, payroll and referral-payout copy.
4. Record native reviewer, legal reviewer, catalog hash and both approval dates in the release ticket. Never silently fall back within a released locale; rollback the entire locale to the reviewed English baseline if parity breaks.

## Offline draft generation

`scripts/generate-locale-catalogs.mjs` creates a complete machine baseline without sending catalog text to a network service. Install CTranslate2-compatible models outside the repository, then provide a JSON manifest through `ERIN_OFFLINE_MODELS`; each locale entry contains the model directory and either `en` or `de` as its source language. Point `ERIN_OFFLINE_PYTHONPATH` at the isolated Python dependencies and run:

```bash
ERIN_OFFLINE_MODELS=/path/to/models.json \
ERIN_OFFLINE_PYTHONPATH=/path/to/python-packages \
node scripts/generate-locale-catalogs.mjs
```

The script protects interpolation placeholders, HTML, URLs and CLI fragments, writes every TypeScript, JSON and Laravel validation catalog, and keeps its ignored cache in `.localization-cache.json`. Downloading public model artifacts is allowed, but the actual catalog generation must remain offline; machine output is a draft and never evidence of native-speaker or legal approval.

## Review evidence

The release ticket must contain the reviewed commit or catalog hash, reviewer names, languages/areas covered, approval dates and links to the seven onboarding screenshots. A native reviewer must cover recruiting, visa and relocation terminology and tone; a legal reviewer must separately approve privacy, terms, consent, insurance, tax/bank, payroll and referral-payout copy. Any changed string invalidates the affected approval until it is reviewed again.
