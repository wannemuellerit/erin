# CI security review — 2026-10-22

The existing security workflow failed before the runner migration. The runner PR
also updates the available security fixes within the existing dependency ranges:
Laravel, CommonMark, Symfony HTTP Foundation/YAML and npm's affected lock entries.

After these updates, `composer audit --locked` reports no advisories and
`npm audit --omit=dev` reports zero vulnerabilities. The existing production image
scan remains enabled and its result must be checked on the updated commit.

The full npm audit still reports four high entries from one dependency chain:
`@vue/eslint-config-typescript → fast-glob → micromatch → braces`. They all trace
to [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm).
The latest published braces version is 3.0.3, and the upstream advisory lists no
patched version. This chain belongs to development/lint tooling, not the
production dependency installation. A forced downgrade of the Vue ESLint config
would conflict with the installed ESLint Vue plugin's peer requirements.

The existing full npm audit gate is retained, including this finding. This note
documents the remaining finding; it does not accept or suppress it. The owner
must decide whether to merge the runner/security-fix PR with this existing tool
finding or require a separately reviewed tooling replacement first.
