# Usayd Academy — Backend API

> **Repository:** `usayd-api`  
> **Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8.4 LTS · Redis 7.4  
> **Architecture:** Modular Monolith (ADR 001)  

---

## 1. Overview
The `usayd-api` repository houses the independent backend API server for Usayd Academy. It provides core identity, scheduling, booking, learning maps, supervisor operations, and payment management via strict, versioned REST endpoints (`/api/v1`) documented in `docs/api/implemented.openapi.json`. The root `openapi.yaml` describes the intended future contract, not implemented behavior.

---

## 2. Setup & Local Development

### Prerequisites
- PHP `>= 8.3` (PHP `8.4` recommended)
- Composer `2.x`
- MySQL `8.4 LTS` (InnoDB strict SQL mode)
- Redis `7.4`

### Installation
```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
php artisan key:generate

# 3. Configure a dedicated local MySQL 8.4 database in .env, then migrate
php artisan migrate
# Never use migrate:fresh against shared, staging, or production data.
```

---

## 3. Health & Readiness Verification

- **Production Health Probe:** `GET /api/v1/health`
- **Root Health Alias:** `GET /api/health`
- **Application Up Probe:** `GET /up`

Returns HTTP 200 with JSON subsystem connectivity (database, redis, runtime latency). Returns HTTP 503 with RFC 7807 problem details if database connection fails.

---

## 4. Quality & Test Execution

```bash
# Validate composer configuration
composer validate --strict

# Run automated test battery against isolated MySQL 8.4 and Redis 7.4 test services
# Set DB_* and REDIS_* to your disposable test services before running.
USAYD_REQUIRE_REDIS_TESTS=1 php artisan test

# Verify code styling (Laravel Pint)
./vendor/bin/pint --test

# Verify implemented OpenAPI against production-registered routes; runs negative fixtures
# Requires installed Composer dependencies and a configured local application.
bash scripts/check-openapi.sh

# Verify architecture domain boundaries
python scripts/verify_architecture_boundaries.py

# Verify MySQL 8.4 database foundations & anti-PostgreSQL rules
python scripts/verify_database_foundations.py

# Verify concurrency control & idempotency harness
python scripts/verify_concurrency_harness.py

# Verify Redis queue partitions & failed-job lifecycle
python scripts/verify_queue_lifecycle.py
```


CI fails closed if required bootstrap files are missing; no pre-bootstrap green fallback. The real Redis test uses unique keys and a dedicated queue, then removes them. Keep the test services isolated from shared environments. The OpenAPI lint checks local references, operation IDs, response status and descriptions, required path parameters, security scheme names and registered route parity, including absence of test routes in the production inventory. It is not a complete OpenAPI specification validator or an end-to-end compatibility test. Only the implemented contract is linted; proposed operations must not be promoted without approval.

---

## 5. Database Architecture & Foundations (MySQL 8.4 LTS)
Detailed in [`docs/architecture/DATABASE_FOUNDATIONS.md`](docs/architecture/DATABASE_FOUNDATIONS.md).
- **Engine:** InnoDB strictly enforced; MyISAM/MEMORY prohibited.
- **Charset & Collation:** `utf8mb4` with `utf8mb4_unicode_ci` (full Arabic Tashkeel & 4-byte emoji support).
- **Strict SQL Mode:** Enforces `ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES`, `NO_ZERO_IN_DATE`, `NO_ZERO_DATE`, `ERROR_FOR_DIVISION_BY_ZERO`, `NO_ENGINE_SUBSTITUTION`.
- **Foundations Schema (Module 0):**
  - `audit_events`: Append-only compliance and event log.
  - `outbox_messages`: Transactional outbox pattern for guaranteed eventual consistency.
  - `idempotency_keys`: Mutation idempotency protection.
  - `settings_versions`: Versioned system configuration.
  - `feature_flags`: Progressive delivery flags.
- **Rollback Verification:** Every migration requires a tested `down()` rollback method.
- **Anti-PostgreSQL Linter:** Prohibits non-portable Postgres-specific syntax.

---

## 6. Concurrency Control & Idempotency Harness
Detailed in [`docs/architecture/CONCURRENCY_AND_IDEMPOTENCY.md`](docs/architecture/CONCURRENCY_AND_IDEMPOTENCY.md).
- **Pessimistic Locking:** `SELECT FOR UPDATE` prevents lost updates and double-booking race conditions.
- **Resilient Transactions:** `ResilientTransaction::run(...)` intercepts transient deadlocks (Error 1213) and retries with backoff.
- **IETF Idempotency Middleware:** `EnforceIdempotency` caches mutating responses and returns `Idempotent-Replayed: true` or RFC 7807 problem details (409 Conflict, 422 Payload Mismatch).

---

## 7. Redis Queues, Horizon & Failed-Job Lifecycle
Detailed in [`docs/architecture/QUEUE_AND_FAILED_JOB_LIFECYCLE.md`](docs/architecture/QUEUE_AND_FAILED_JOB_LIFECYCLE.md).
- **Queue Partitions:** `high`, `default`, `notifications`, `reports` isolated via Redis.
- **Transactional Dispatch:** `after_commit => true` prevents race conditions.
- **Retry & Backoff:** `BaseQueueJob` implements exponential backoff `[5, 15, 60]` seconds with 3 max attempts.
- **Dead-Letter Review:** Terminal failures emit audit events and store full traces in `failed_jobs`; inspectable and replayable via `php artisan usayd:queue-dead-letter`.
- **Operational Metrics:** `GET /api/v1/queue/metrics` reports partition depths and failure rates.



