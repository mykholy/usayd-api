# Staging release and reversible migration protocol

USYD-T0034 / [USYD-77](https://mykholy.atlassian.net/browse/USYD-77), S02. Prepared for future deployment; no environment provisioned, no live credentials, no actual staging deployment. Owner explicitly deferred server provisioning.

## Environment matrix

| Setting | Local | CI | Staging | Production |
|---|---|---|---|---|
| APP_ENV | local | testing (production for smoke) | staging | production |
| APP_DEBUG | optional, synthetic data only | false for readiness | false | false |
| Database | dedicated MySQL 8.4 | disposable MySQL 8.4 | isolated MySQL 8.4 | isolated MySQL 8.4 |
| Redis | dedicated | disposable Redis 7.4 | isolated Redis | isolated Redis |
| Private objects | local private | loopback S3-compatible | separate private bucket | separate private bucket |
| Credentials | untracked local config | disposable fixtures | workload identity / secret manager | separate workload identity / secret manager |
| User data | synthetic | synthetic | synthetic or approved anonymized | approved purposes only |
| Recording / AI | off unless isolated fixture | off unless isolated fixture | off | off pending approval |

Never copy production data or keys into lower environments. Do not regenerate an existing environment APP_KEY; signed links and encryption depend on it. Scope runtime database permissions separately from migration permissions. Keep payment, minor enrollment and recording/AI flags gated by the decision register. Do not expose test harness routes.

## Infrastructure decision (2026-11-26)

AWS Frankfurt (eu-central-1) is a provisional candidate, not a provisioned region or approval to transfer user data. AWS Health reports extended Bahrain/UAE disruptions. Revalidate at provisioning: current AWS Health, service/instance availability, latency and packet loss from Egypt and each launch market, a workload-specific AWS Pricing Calculator estimate, and legal/DPO cross-border processing permission. If lawful residency cannot be satisfied, do not activate the affected market. Keep region configurable; do not hard-code production region. No cross-region backup or replication without approved residency and retention policy.

Sources: [AWS region list](https://docs.aws.amazon.com/global-infrastructure/latest/regions/aws-regions.html), [AWS Health](https://health.aws.amazon.com/health/status), [AWS data privacy](https://aws.amazon.com/compliance/data-privacy-faq/), [AWS Pricing Calculator](https://calculator.aws/).

## Release checklist (future staging and production)

1. Record exact commit/artifact digest, migration plan, operator/checker, expected health, backout triggers, previous release and approved change window. Require green CI for that SHA; preserve lockfiles and API compatibility. Use immutable release artifact and environment-specific configuration.
2. Verify backups (encrypted DB snapshot or backup/PITR, approved private object backup, config inventory); record recovery point, retention, access policy, and restore proof on isolated environment. Set RPO/RTO with owner; do not invent values. No release if restore proof or backup is missing.
3. Rehearse migrations on isolated representative MySQL 8.4 dataset. Inspect locks, runtime, table size and query plans; use expand/backfill/contract. Add compatible schema first, then deploy code compatible with both versions, backfill in bounded idempotent batches and validate counts/invariants. Destructive contract changes are separate later reviewed release; do not assume ALTER is lock-free. Do not claim zero downtime until measured.
4. Before switching traffic, verify configuration, private S3 and scanner signature freshness, queue/scheduler ownership and flags, and readiness. Migration is a single authorized operator step using `php artisan migrate --force --isolated` only after backup, rehearsal and lock support confirmed. Never run `migrate:fresh`, `db:wipe` or automatic destructive rollback against shared environments.
5. Switch traffic gradually using approved infrastructure. Monitor error rates, latency, DB/Redis connectivity, queue backlog, failed jobs and trace IDs. Smoke before and after traffic switch: `python3 scripts/verify_release_health.py https://<approved-api-origin>`. The script fails for non-200, non-JSON, degraded, disconnected DB/Redis, or exposed diagnostics. It sends no credentials and does not follow redirects. Run authenticated journey checks with synthetic accounts separately. No raw health payload or secrets in logs.
6. On failure stop rollout, restore previous application artifact and compatible config, drain/restart workers against compatible schema, and re-run smoke. Prefer forward-fix when new schema is backwards compatible. A migration down is permitted only after tested data-preserving behavior, explicit approval and backup; never blindly roll back a production migration. A restore can lose later writes: use incident command, preserve evidence, reconcile transactions and obtain explicit authorization before restore. Record recovery and affected consumers.

## Verification evidence required

- `python3 -m unittest discover -s scripts/tests -p test_release_health.py`
- `python3 scripts/verify_release_health.py http://127.0.0.1:8000` against production-mode API with disposable MySQL/Redis.
- Existing CI: Composer, Pint, architecture, migration, MySQL/Redis/S3/ClamAV, PHP and OpenAPI checks.
- Before actual deployment: backup/restore rehearsal, migration timing and concurrent traffic proof, authenticated smoke, release switch/backout proof, health/metrics evidence and operator/checker approval. These are outstanding; CI does not prove live staging or zero-downtime behavior.
