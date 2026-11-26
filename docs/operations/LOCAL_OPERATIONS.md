# Local operations and incident runbook

USYD-T0035 / [USYD-78](https://mykholy.atlassian.net/browse/USYD-78), S02. This is the API contribution to the cross-team operational documentation. Cross-repository handoff and named specialist acceptance are separate outstanding evidence. No live deployment or production secrets.

## Runtime decision (ADR addendum)

Use the current committed Composer lockfile, PHP 8.4.1 or later in the tested 8.4 series (CI uses PHP 8.4), MySQL 8.4 and Redis 7.4. Several locked Symfony 8.1 packages require PHP >=8.4.1; the broader root composer.json constraint does not prove PHP 8.3 compatibility. Run Composer platform checks; do not use --ignore-platform-reqs. Horizon configuration is present but laravel/horizon is not installed in the current lockfile. Use Laravel's built-in queue workers, not php artisan horizon. A Horizon rollout needs its own compatible dependency, authorization and integration tests.

## Local bootstrap (synthetic data only)

1. Clone the approved API revision. Install PHP extensions required by Composer, Composer 2, Python 3, MySQL 8.4 and Redis 7.4. Use separate local and test databases and isolated Redis instances. Never use a shared database or Redis for test execution.
2. Run `composer validate --strict` and `composer install --no-interaction --prefer-dist`, followed by `composer check-platform-reqs`. Copy .env.example to .env only if missing; configure dedicated local DB_* and Redis settings. Generate an APP_KEY only for a brand-new disposable environment. Keep credentials in untracked local configuration; never paste them into Jira, logs or chat. Do not use composer setup on an existing environment because its script regenerates the key and runs migrations.
3. Confirm environment and database ownership before running `php artisan migrate`. Never run migrate:fresh against any shared environment. Start the local server with `php artisan serve --host=127.0.0.1 --port=8000`.
4. Check `php artisan migrate:status` and `python3 scripts/verify_release_health.py http://127.0.0.1:8000` against isolated dependencies. Use APP_DEBUG=false for readiness. The probe fails on degraded DB/Redis or exposed diagnostics. /up alone is not readiness.
5. For isolated tests set DB_* explicitly to a disposable MySQL database, separate from the local database. Set CACHE_STORE=redis and QUEUE_CONNECTION=redis for integration tests as in CI. Follow [release protocol](RELEASE_PROTOCOL.md), [supply chain security](SUPPLY_CHAIN_SECURITY.md), [contract publication](../api/CONTRACT_PUBLICATION.md) and [storage](../api/STORAGE.md) for mandatory integration coverage and prerequisites. Required S3/ClamAV checks use disposable local services; no real AWS credentials needed.

## Queue operations

- Use one worker process per queue connection: redis-high, redis, redis-notifications, redis-reports. The worker timeout must be below the corresponding retry_after and compatible with the job timeout; current retry_after values are 30/90/60/180 seconds respectively. Do not copy unverified Horizon supervisor timeout settings into production. Reconcile actual job execution budgets and process supervision before rollout.
- Read-only failed-job inventory: `php artisan usayd:queue-dead-letter --action=list`. Restricted inspection: `php artisan usayd:queue-dead-letter --action=inspect --id=<approved-uuid>`. Exception text can contain sensitive data; restrict terminal access and redact evidence.
- Do not retry without confirming the operation is idempotent, its prerequisite is resolved and an authorized operator approves the specific job. No bulk retry or flush under this runbook. Preserve audit evidence and retention/legal holds.
- Monitor queue depths, failures and latency from an authorized context. Do not assume a configured dashboard is deployed or public.

## Incident triage

| Symptom | First safe action | Escalation |
|---|---|---|
| Health 503 / degraded | Stop promotion, inspect DB/Redis connectivity and isolated readiness result | Backend/operations |
| Migration lock or pending migration | Stop rollout; resolve lock, verify migration status | DBA/technical lead |
| Queue backlog or failed jobs | Inspect restricted metrics and list; no automatic replay | Operations and domain owner |
| Security advisory or secret finding | Stop merge, identify dependency or finding metadata; genuine credentials require revocation/rotation by authorized owner | Security |
| Unavailable scanner / private storage | Reject affected uploads; keep gated features disabled | Security/operations |
| Minor/consent/data incident | Restrict affected operation, preserve minimal audit evidence, do not expose personal data | Safeguarding and legal/DPO |

## Approvers and handoff

Project owner and technical contact: Ahmed El-Kholy (current Jira assignee/reporter for USYD-78; not evidence of independent approval). Named legal/DPO, academic authority, finance, safeguarding/security, operations and independent technical reviewer are not confirmed in the owner decision register. Do not invent identities or conflate owner direction with specialist signoff. Each release must record actual person, decision, scope, date and evidence. Use [Delivery owner decision register](https://github.com/mykholy/usayd-delivery/pull/1) for current proposed overlay; confirm merged authoritative version before launch. Flutter remains with the mobile developer; no implementation changes in this task.

## Verification

Run the existing API CI and `python3 -m unittest discover -s scripts/tests -p test_operational_bootstrap.py`. The operational verifier checks documented command availability against the actual Artisan command inventory, locked PHP platform compatibility and Horizon dependency status. It performs no deployment, migration, queue retry or data deletion. CI results do not substitute for real staging/restore/consumer signoff.
