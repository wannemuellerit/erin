# CI security review — 2026-10-22

The existing security workflow failed before the runner migration. This change
updates the available fixes within the existing dependency ranges: Laravel,
CommonMark, Symfony HTTP Foundation/YAML and npm's affected lock entries.

The remaining npm finding came from
`@vue/eslint-config-typescript → fast-glob → micromatch → braces`.
[GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) lists no
patched braces release. The affected configuration wrapper is replaced with
ESLint's own `defineConfig`, the existing typescript-eslint recommended config,
and the Vue parser. The replacement retains the existing type-aware project
service, Vue script language restrictions, custom rules and ignore patterns.

Before removing the wrapper, effective rule maps, primary parser assignments,
source type and ECMAScript versions were captured for all 302 Vue, TypeScript
and JavaScript paths under resources/js and scripts, plus eslint.config.js.
Comparing the replacement produced zero differences, including ignored paths.
The complete ESLint check also passes with the wrapper removed.

After these updates, `composer audit --locked` reports no advisories, the full
`npm audit` reports zero vulnerabilities and `npm audit --omit=dev` reports zero
vulnerabilities. The existing Composer/npm audit gates and container scan remain
enabled. Their live CI results must be checked on the updated commit before
merging.

The framework update adds the HTTP QUERY verb. Wayfinder is updated to 0.1.23,
whose generated Method type supports it; Vue/TypeScript checks pass after fresh
generation. Four CLI commands narrow console arguments/options before using them
as strings or arrays, preserving the existing operational guards under stricter
framework types. PHPStan and Pint pass; the 50 focused Stripe-plan, local-restore
and synthetic-pilot regression tests pass with 285 assertions.
