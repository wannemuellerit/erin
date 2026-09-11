# Horizon operations

Erin uses Laravel Horizon 5.48 with Laravel 13 and Redis. Horizon owns the
production queue consumers; the former plain `queue:work` container remains an
explicit rollback profile and must never run at the same time as Horizon.

## Queue policy

| Queue | Workload | Attempts / backoff | Timeout |
| --- | --- | --- | --- |
| `default` | ordinary asynchronous work | 3 / 10, 60, 300 seconds | 120 seconds |
| `notifications` | mail, database, broadcast, push, SMS and WhatsApp | 5 / 10, 60, 300 seconds | 60 seconds |
| `scans` | ClamAV and uploaded-media scanning | 3 / 30, 120, 600 seconds | 180 seconds |
| `imports` | candidate imports | 2 / 60, 300 seconds | 900 seconds |
| `webhooks` | provider inboxes, outboxes and reconciliation | 5 / 10, 60, 300 seconds | 120 seconds |
| `ai` | asynchronous AI workloads | 2 / 30, 180 seconds | 180 seconds |
| `payments` | referral payout intents and reconciliation | 5 / 30, 120, 600 seconds | 120 seconds |

`REDIS_QUEUE_RETRY_AFTER` must stay longer than the highest supervisor timeout.
All producers use idempotency keys, durable inbox/outbox records or guarded
state transitions where an external side effect can occur.

## Deployment and migration

1. Put the application into maintenance mode and let the current worker finish
   its active job.
2. Stop the old worker before starting Horizon. Do not overlap consumers during this cutover;
   both consume the same Redis lists.
3. Deploy dependencies and configuration, then run migrations.
4. Start `horizon` and `scheduler`, and run `php artisan horizon:status`.
5. Run `php artisan erin:ops:queue-health --json` and confirm that every named
   queue is available and below its threshold.
6. Leave maintenance mode only after the status and queue probes pass.

During routine deployments, run `php artisan horizon:terminate`; the process
manager starts a fresh master with the new code. The scheduler records Horizon
metrics every five minutes with `horizon:snapshot`.

## Monitoring and access

The dashboard is mounted at `/horizon`. Access requires authentication, a
verified email, the `super_admin` role and confirmed staff two-factor
authentication. Dashboard JSON is passed through `RedactHorizonPayloads`, which
removes serialized commands and exception details before they reach a browser.

Alert on `horizon:status`, `erin_queue_backlog_jobs`, failed jobs and Horizon's
long-wait notifications. Set `HORIZON_ALERT_EMAIL` to route Horizon alerts to an
operational mailbox.

## Rollback

1. Enter maintenance mode and run `php artisan horizon:terminate`.
2. Stop the `horizon` service and verify that no Horizon master or supervisor is
   consuming jobs.
3. Start the fallback worker with
   `docker compose --profile queue-fallback up -d queue`.
4. Run `php artisan erin:ops:queue-health --json`, process one idempotent canary
   job, and verify that the backlog decreases exactly once.
5. Leave maintenance mode. To return to Horizon, repeat the cutover in reverse:
   stop `queue` first, then start `horizon`.

Never purge Redis queues during migration or rollback. Pending jobs remain on
the same named Redis lists and are consumed after the selected worker starts.
