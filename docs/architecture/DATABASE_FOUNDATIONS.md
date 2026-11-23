# Usayd Academy: Database Architecture & Foundations (MySQL 8.4 LTS)

> **Document Key:** `USYD-ARCH-002` · **Authoritative Spec:** `GLOBAL_DOMAIN_SCHEMA_v1.2.md` · **Related Task:** `USYD-T0024` / `USYD-57`

## 1. Architectural Principles

Usayd Academy mandates **MySQL 8.4 LTS** as the sole relational database across all environments (local development, CI testing, and production). SQLite is strictly prohibited for integration and concurrency testing due to differences in locking granularity, collation, JSON extraction semantics, and transaction isolation.

### Core Database Invariants
1. **Engine:** `InnoDB` exclusively. No MyISAM or MEMORY tables.
2. **Character Set & Collation:** `utf8mb4` with `utf8mb4_unicode_ci` (full Unicode support for Arabic Quranic text with diacritics / Tashkeel, international names, and 4-byte emojis).
3. **Strict SQL Modes:**
   - `ONLY_FULL_GROUP_BY`
   - `STRICT_TRANS_TABLES`
   - `NO_ZERO_IN_DATE`
   - `NO_ZERO_DATE`
   - `ERROR_FOR_DIVISION_BY_ZERO`
   - `NO_ENGINE_SUBSTITUTION`
4. **Time & Money Precision:**
   - All timestamps stored in UTC (`TIMESTAMP` or `DATETIME` normalized to UTC).
   - All financial amounts stored as integer minor units (`amount_minor BIGINT`) with ISO 4217 currency codes. 3-decimal currencies (KWD, BHD, OMR) supported.
5. **No PostgreSQL-specific Syntax:** Queries, migrations, and raw statements must not use PostgreSQL-specific syntax (`ILIKE`, `::json`, `::jsonb`, `RETURNING`, `gen_random_uuid()`, `now() at time zone`).

---

## 2. Foundational Tables (Module 0)

### 2.1. `audit_events` (Append-Only Audit Ledger)
Provides an immutable audit log for administrative decisions, security events, parental consent verification, and academic score modifications.
- **Table:** `audit_events`
- **Columns:**
  - `id`: BIGINT UNSIGNED AUTO_INCREMENT
  - `trace_id`: VARCHAR(64) NULL INDEX (distributed request tracing)
  - `actor_id`: BIGINT UNSIGNED NULL INDEX (user who performed action)
  - `actor_type`: VARCHAR(50) NULL (user, system, webhook, ai_service)
  - `subject_type`: VARCHAR(100) INDEX (audited entity type)
  - `subject_id`: VARCHAR(100) INDEX (audited entity identifier)
  - `action`: VARCHAR(100) INDEX (domain action verb)
  - `old_hash`: VARCHAR(64) NULL (SHA256 hash of pre-state)
  - `new_hash`: VARCHAR(64) NULL (SHA256 hash of post-state)
  - `purpose`: VARCHAR(255) NULL (legal/operational justification)
  - `ip_hash`: VARCHAR(64) NULL (anonymized IP hash for privacy compliance)
  - `metadata`: JSON NULL (structured contextual metadata)
  - `created_at`: TIMESTAMP DEFAULT CURRENT_TIMESTAMP INDEX
- **Invariants:** Append-only. No `updated_at` column. Deletions and updates are prohibited.

### 2.2. `outbox_messages` (Transactional Outbox Pattern)
Ensures reliable asynchronous event publishing without distributed transactions.
- **Table:** `outbox_messages`
- **Columns:**
  - `id`: CHAR(36) UUID PRIMARY KEY
  - `aggregate_type`: VARCHAR(100) INDEX
  - `aggregate_id`: VARCHAR(100) INDEX
  - `event_type`: VARCHAR(150) INDEX
  - `payload`: JSON NOT NULL
  - `headers`: JSON NULL
  - `status`: VARCHAR(30) DEFAULT 'pending' INDEX (pending, processing, published, failed)
  - `attempts`: SMALLINT UNSIGNED DEFAULT 0
  - `error_message`: TEXT NULL
  - `scheduled_at`: TIMESTAMP NULL INDEX
  - `processed_at`: TIMESTAMP NULL
  - `created_at`, `updated_at`: TIMESTAMPS
- **Worker Index:** `(status, scheduled_at)` for high-throughput polling.

### 2.3. `idempotency_keys` (RFC 7807 & Mutation Protection)
Guarantees at-most-once execution for all mutating API requests (payments, slot holds, enrollments).
- **Table:** `idempotency_keys`
- **Columns:**
  - `id`: BIGINT UNSIGNED AUTO_INCREMENT
  - `key`: VARCHAR(255) UNIQUE
  - `user_id`: BIGINT UNSIGNED NULL INDEX
  - `route`: VARCHAR(255) NOT NULL
  - `request_hash`: VARCHAR(64) NOT NULL
  - `response_code`: SMALLINT NULL
  - `response_headers`: JSON NULL
  - `response_body`: JSON NULL
  - `status`: VARCHAR(30) DEFAULT 'in_flight' INDEX (in_flight, completed, failed)
  - `expires_at`: TIMESTAMP INDEX
  - `created_at`, `updated_at`: TIMESTAMPS

### 2.4. `settings_versions` (Versioned Configuration)
Maintains versioned system parameters, pricing rules, and video routing policies with dual-approval tracking.
- **Table:** `settings_versions`
- **Columns:**
  - `id`: BIGINT UNSIGNED AUTO_INCREMENT
  - `namespace`: VARCHAR(100) INDEX
  - `scope`: VARCHAR(100) DEFAULT 'global' INDEX
  - `schema_version`: VARCHAR(20) DEFAULT '1.0'
  - `settings`: JSON NOT NULL
  - `status`: VARCHAR(30) DEFAULT 'draft' INDEX (draft, proposed, approved, active, archived)
  - `checksum`: VARCHAR(64) NOT NULL
  - `effective_at`: TIMESTAMP NULL INDEX
  - `proposed_by`: BIGINT UNSIGNED NULL
  - `approved_by`: BIGINT UNSIGNED NULL
  - `created_at`, `updated_at`: TIMESTAMPS

### 2.5. `feature_flags` (Progressive Delivery Flags)
Controls progressive activation of implementation gates (G0–G8) and owner decisions.
- **Table:** `feature_flags`
- **Columns:**
  - `id`: BIGINT UNSIGNED AUTO_INCREMENT
  - `key`: VARCHAR(100) UNIQUE
  - `name`: VARCHAR(150) NOT NULL
  - `description`: TEXT NULL
  - `is_enabled`: BOOLEAN DEFAULT FALSE INDEX
  - `rules`: JSON NULL
  - `created_at`, `updated_at`: TIMESTAMPS

---

## 3. Migration and Rollback Governance

Every database migration in Usayd Academy must adhere to the following rules:
1. **Symmetry:** Every migration file must implement a complete, tested `down()` method that restores the previous schema state without data corruption or orphan foreign keys.
2. **Explicit Engine & Collation:** Table creation statements should define `$table->engine = 'InnoDB'` and `$table->charset = 'utf8mb4'`.
3. **Automated Verification:** The migration rollback test (`tests/Feature/Database/MigrationRollbackTest.php`) executes `migrate:rollback` and `migrate` during CI to prove clean reversibility.
4. **Anti-Postgres Linter:** `tests/Feature/Database/AntiPostgresLinterTest.php` and `scripts/verify_database_foundations.py` automatically block any pull request introducing non-portable SQL syntax.
