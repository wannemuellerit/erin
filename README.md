# Erin

Erin is a Laravel 13, Inertia 3 and Vue 3 recruiting platform. The repository
is based on the official
[`laravel/vue-starter-kit`](https://github.com/laravel/vue-starter-kit) and
ships with a Docker-first development environment.

## Runtime containers

| Service       | Purpose                                         | Host port       |
| ------------- | ----------------------------------------------- | --------------- |
| `laravel`     | Laravel development server, PHP 8.4             | `8000`          |
| `vite`        | Vite development/HMR server, Node 22            | `5173`          |
| `mysql`       | MySQL 8.4 LTS                                   | `3306`          |
| `redis`       | Cache, queues and distributed locks             | `6379`          |
| `queue`       | Redis-backed Laravel worker                     | -               |
| `scheduler`   | Laravel scheduler                               | -               |
| `reverb`      | Pusher-compatible WebSocket server              | `8080`          |
| `meilisearch` | Full-text and faceted search                    | `7700`          |
| `minio`       | Private S3-compatible object storage            | `9000`          |
| `minio-init`  | Creates the private application bucket          | -               |
| `clamav`      | Malware scanner for uploaded files              | internal `3310` |
| `mailpit`     | Local SMTP inbox and web UI                     | `8025`          |
| `setup`       | Composer install, key generation and migrations | -               |
| `node-setup`  | Deterministic `npm ci` install                  | -               |
| `stripe-cli`  | Optional local Stripe webhook forwarding        | -               |

Persistent state is stored in named Docker volumes. Source code, `vendor` and
`node_modules` are bind-mounted so the IDE and all containers use the same
dependency state.

The queue visibility timeout (`REDIS_QUEUE_RETRY_AFTER`) is deliberately longer
than the worker timeout. Keep that invariant when increasing
`queue:work --timeout`, otherwise a long-running job can be delivered twice.

## Start

```bash
cp .env.example .env
docker compose up --build -d
docker compose ps
```

The first start installs dependencies, creates an application key, prepares
local VAPID keys for browser push, prepares the private MinIO bucket and runs
database migrations. Open:

- Application: <http://localhost:8000>
- Telescope: <http://localhost:8000/telescope>
- Vite: <http://localhost:5173>
- Reverb: `ws://localhost:8080/app/{key}`
- Meilisearch: <http://localhost:7700>
- MinIO console: <http://localhost:9001>
- Mailpit: <http://localhost:8025>

The credentials in `.env.example` belong only to the local containers. Replace
them with secrets from a secret manager in every deployed environment.

## Demo accounts

With `APP_DEMO_MODE=true`, the default seeder creates verified accounts for the
Superadmin, two sample companies and ten sample candidates. Every demo account
uses the password `password`:

| Role | Email |
| --- | --- |
| Superadmin | `admin@wannemueller.dev` |
| Müller Elektrotechnik | `unternehmen.mueller@wannemueller.dev` |
| RheinCargo Logistik | `unternehmen.rheincargo@wannemueller.dev` |
| Candidates 1–10 | `candidate01@wannemueller.dev` through `candidate10@wannemueller.dev` |

The login page lists all 13 demo accounts grouped by role and can insert the
selected credentials automatically while demo mode is active. Demo credentials
and the seeder are disabled in production.

## Isolated tools

Pest uses the separate `erin_testing` MySQL database. Tool containers run only
when requested:

```bash
docker compose run --rm pest
docker compose run --rm phpstan
docker compose run --rm wayfinder
docker compose run --rm node-setup npm run types:check
docker compose run --rm node-setup npm run lint:check
docker compose run --rm node-setup npm run format:check
docker compose run --rm playwright
```

The browser suite uses deterministic demo fixtures for all roles, onboarding,
tenant isolation, read-only support views, mobile layouts and serious WCAG
violations. Reset those fixtures before a local run:

```bash
docker compose exec laravel php artisan db:seed --force --no-interaction
docker compose exec laravel php artisan db:seed --class='Database\Seeders\BrowserTestSeeder' --force --no-interaction
docker compose --profile tools run --rm playwright
```

Run Artisan or Composer inside PHP:

```bash
docker compose exec laravel php artisan about
docker compose run --rm setup composer validate --strict
```

Wayfinder generation belongs to PHP because it invokes Artisan. After changing
routes, run `docker compose run --rm wayfinder`.

## PHP-FPM and Nginx smoke-test profile

The default stack keeps the quick Laravel development server. A separate
production-shaped PHP-FPM/Nginx path can be exercised locally:

```bash
docker compose --profile production up --build -d php-fpm nginx
```

It is available at <http://localhost:8081>. This profile is intended only as a
quick local topology check.

## Immutable production stack

[`compose.production.yaml`](compose.production.yaml) builds separate immutable
PHP-FPM and Nginx images through
[`docker/production/Dockerfile`](docker/production/Dockerfile). The Vite bundle
is generated in a build stage and copied into both runtime images. There is no
Vite service or public development server in this stack.

Use [`docker/production/env.example`](docker/production/env.example) as the
variable inventory and provide the real values through the deployment secret
manager. `ERIN_RUNTIME_ENV_SECRET_FILE` points to a root-readable runtime
secret file mounted through Docker Secrets; it is loaded only by the
entrypoint and must never enter an image layer or repository. Keep the
production file separate from the local `.env`; Compose reads the selected file
during interpolation, including build arguments. Then build and run the
one-shot migration before starting the services:

```bash
cp docker/production/env.example .env.production
docker compose --env-file .env.production -f compose.production.yaml config -q
docker compose --env-file .env.production -f compose.production.yaml build
docker compose --env-file .env.production -f compose.production.yaml --profile deploy run --rm migrate
docker compose --env-file .env.production -f compose.production.yaml up -d
```

Nginx serves static assets, proxies PHP to PHP-FPM and forwards Reverb WebSocket
paths internally. MySQL, Redis, Meilisearch, MinIO and ClamAV have no published
ports. A public deployment still needs TLS/reverse-proxy configuration,
off-host encrypted backups, monitoring and rotated secrets.

The production Redis service enables `requirepass` when `REDIS_PASSWORD` is
set; the same value is used by Laravel and Reverb. PHP-FPM, Reverb and Nginx
publish container healthchecks, and application processes start only after
MySQL, Redis, Meilisearch, MinIO initialization and ClamAV are ready. The
service worker is always served with explicit no-cache headers so browser-push
updates are not pinned behind an immutable asset cache.

Wildcard proxy trust is rejected. `TRUSTED_PROXIES` must contain only the
explicit internal Docker CIDR and any separately reviewed ingress proxy CIDRs.

## Release, Betrieb und Wiederherstellung

Die Workflows sind bewusst in getrennte, diagnostizierbare Gates aufgeteilt:

- `tests`: Pint, PHPStan Level 7, Pest, Vue-Typecheck, ESLint, Prettier,
  i18n und Vite-Build.
- `security`: Composer-/npm-Audit, PHPStan, CodeQL, Gitleaks, Trivy und SPDX-SBOM.
- `release-images`: SHA-getaggte Images, Provenienz und keyless Cosign-Signatur.
- `deploy`: Attestierungsprüfung, Readiness, gesperrte Migration, Smoke-Test und
  automatischer App-Rollback.
- `encrypted-backup`: alle sechs Stunden verschlüsseltes MySQL-/MinIO-Backup
  in ein getrenntes Restic-Ziel mit Check und Retention.

Die ausführbaren Abläufe und Entscheidungspunkte stehen in
[`docs/operations/deployment-runbook.md`](docs/operations/deployment-runbook.md),
[`docs/operations/backup-restore-drill.md`](docs/operations/backup-restore-drill.md)
und
[`docs/operations/incident-runbooks.md`](docs/operations/incident-runbooks.md).

## Billing and external services

Laravel Cashier uses Stripe Billing and Checkout. Erin deliberately maps
Cashier to these project variables:

```dotenv
STRIPE_PUBLISHABLE_KEY=
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
STRIPE_MAX_NETWORK_RETRIES=2
STRIPE_PRODUCT_BASIC=
STRIPE_PRICE_BASIC=
STRIPE_PRODUCT_BUSINESS=
STRIPE_PRICE_BUSINESS=
STRIPE_PRODUCT_PREMIUM=
STRIPE_PRICE_PREMIUM=
STRIPE_PRICE_RECRUITER_SEAT=
STRIPE_PRICE_VISA_PACKAGE=
```

Never enable paid access from the browser redirect alone. The application must
wait for a verified, idempotently processed Stripe webhook. Subscription
webhooks are serialized per customer and reloaded from Stripe before Cashier is
mutated; opaque event IDs are used only for exact deduplication.
Signed events are additionally rejected when their `livemode` does not match
the configured Secret Key. One-time visa purchases carry an Erin HMAC over
company, quantity and immutable Price reference, so paid sessions created
outside the application cannot forge credits through metadata alone.

The catalog command is a dry-run unless `--apply` is explicitly provided. It
stores newly created immutable Product and recurring Price IDs on the plan and
never prints Stripe keys or raw provider errors:

```bash
docker compose exec laravel php artisan erin:stripe:sync-plans
docker compose exec laravel php artisan erin:stripe:sync-plans --plan=basic
docker compose exec laravel php artisan erin:stripe:sync-plans --apply
```

Before accepting a staging deployment, run the dedicated non-mutating check.
Without `--remote` it validates keys, locally handled webhook events, the HTTPS
staging URL and all local package mappings. With `--remote` it additionally
retrieves the configured test Prices and registered webhook endpoints. It
compares Price data as well as the expected Cashier path, endpoint status and
required remote event types; it never creates or updates Stripe objects:

```bash
docker compose exec laravel php artisan erin:stripe:staging-check
docker compose exec laravel php artisan erin:stripe:staging-check --remote
```

Use `--apply` only with a Stripe test key during setup. Live mutations additionally
require `--allow-live`, `APP_ENV=production`, a public HTTPS `APP_URL` and an
interactive confirmation. Non-interactive live runs are always rejected, including
calls with `--no-interaction`; the read-only readiness overview is available to
Superadmins under `Admin → Paket & Abrechnung`.

For local webhook testing, put only a Stripe test secret into `.env`, start the
optional listener and copy the emitted `whsec_…` value to
`STRIPE_WEBHOOK_SECRET`:

```bash
docker compose --profile stripe up -d stripe-cli
docker compose logs -f stripe-cli
docker compose restart laravel
docker compose --profile stripe run --rm stripe-cli trigger customer.subscription.created
```

The automated test suite does not call Stripe and uses signed local webhook
payloads. It covers invalid and expired signatures, wrong event modes, replay
and ordering, Product/Price drift, throttling, provider timeouts/429/5xx,
cancellation boundaries and entitlement exhaustion. The operational matrix is
documented in `docs/operations/stripe-staging.md`; production products, prices,
webhook endpoints and keys must be configured through the protected deployment
process.

Zammad remains an external managed service. Erin stores every support message
locally first and then synchronizes tickets and public replies through queued,
retryable API jobs. Stable operation markers reconcile uncertain writes before
a retry creates anything remotely. Configure a dedicated Zammad agent token
and a regular webhook with the following values:

```dotenv
ZAMMAD_ENABLED=true
ZAMMAD_URL=https://support.example.com
ZAMMAD_TOKEN=
ZAMMAD_GROUP=Users
ZAMMAD_WEBHOOK_SECRET=
ZAMMAD_WEBHOOK_CALLBACK_URL=https://app.example.com/integrations/zammad/webhook
ZAMMAD_ALLOW_LOCAL_HTTP=false
ZAMMAD_LOCAL_HTTP_HOSTS=
ZAMMAD_TIMEOUT=10
```

The Zammad webhook endpoint is
`https://<erin-domain>/integrations/zammad/webhook`. Set the same HMAC-SHA1
signature token as `ZAMMAD_WEBHOOK_SECRET`; Erin validates `X-Hub-Signature`
and deduplicates deliveries by `X-Zammad-Delivery`. Internal Zammad notes stay
staff-only, while public replies appear live in the shared Erin support chat.

OpenAI and LiveKit are external managed services and therefore do not get local
containers. Their placeholders live in `.env.example`. Sensitive-document AI
is disabled by default until an approved EU endpoint and the required data
controls are configured. LiveKit token signing uses `firebase/php-jwt`; browser
clients use `livekit-client`.

Scout is configured for Meilisearch, private files for MinIO/S3, and browser
push for VAPID. Uploaded documents must remain private and pass ClamAV before
they are made available.

### Horizon compatibility

The current stable Laravel Horizon release supports Illuminate through Laravel
12, while this project runs Laravel 13. The stack therefore uses a
production-capable Redis `queue:work` process instead of forcing an incompatible
package. Replace the worker command with `php artisan horizon` once a stable,
Laravel-13-compatible Horizon release is available.

## Reverb or Pusher Channels

Local development defaults to Reverb. It implements the Pusher protocol and
has its own long-running container. A Pusher Channels account is external and
does not need another container.

To use Pusher Channels, copy the documented `PUSHER_*` variables from
`.env.example`, set `BROADCAST_CONNECTION=pusher`, and restart Laravel, Vite
and the queue worker. The `reverb` service can then be stopped.

## Ports and shutdown

All published ports can be changed through the corresponding `*_FORWARD_PORT`
variables in `.env`.

```bash
docker compose down
```

To delete all local MySQL, Redis, search, object-storage, malware-definition and
mail data as well, use `docker compose down -v`.
