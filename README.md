# Usayd Academy — Backend API

> **Repository:** `usayd-api`  
> **Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8.4 LTS · Redis 7.4  
> **Architecture:** Modular Monolith (ADR 001)  

---

## 1. Overview
The `usayd-api` repository houses the independent backend API server for Usayd Academy. It provides core identity, scheduling, booking, learning maps, supervisor operations, and payment management via strict, versioned REST endpoints (`/api/v1`) documented in `openapi.yaml`.

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

# 3. Run database migrations on MySQL 8.4
php artisan migrate:fresh
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

# Run automated test battery against MySQL 8.4 service database
php artisan test

# Verify code styling (Laravel Pint)
./vendor/bin/pint --test

# Verify OpenAPI contract alignment
bash scripts/check-openapi.sh

# Verify architecture domain boundaries
python scripts/verify_architecture_boundaries.py

# Verify MySQL 8.4 database foundations & anti-PostgreSQL rules
python scripts/verify_database_foundations.py

# Verify concurrency control & idempotency harness
python scripts/verify_concurrency_harness.py
```

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


