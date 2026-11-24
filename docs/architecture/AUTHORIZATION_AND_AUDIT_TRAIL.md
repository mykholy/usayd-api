# Architecture Specification: Authorization Gates, RBAC & Append-Only Audit Trail

## Status
- **Status:** Approved & Implemented
- **Date:** 2026-11-24
- **Owning Component:** `usayd-api`
- **Issue Reference:** `USYD-T0029` (Sprint S01)

## Context & Architecture
Usayd Academy requires strict least-privilege access control based on the three-dimensional Authorization Matrix:
$$\text{Access} = \text{Role (RBAC)} \times \text{Scope (Assignment)} \times \text{Ownership (Self)}$$

## Enforcement Rules & Invariants

### 1. Three-Dimensional Authorization Matrix
- **Ownership (`O`):** A learner or teacher can access their own direct data (e.g., student accessing their own learning plan, progress, and submissions).
- **Scope / Assignment (`A`):** A teacher can only access data of students or classrooms to which they are assigned.
- **Role-Authorized Supervision (`R`):**
  - `academic`: Full curriculum supervision, grade approvals, and educational overrides.
  - `admin`: Governance, platform configuration, and administrative audits.
  - `support`: Limited support access governed by explicit purpose.
  - `finance`: Financial and payout ledgers.

### 2. Mandatory Business Purpose / Reason Metadata
Privileged actions (such as administrative overrides, status corrections, and personal data exports) require mandatory documented justification:
- Passed via `X-Audit-Reason` header or `reason` / `purpose` request attributes.
- Enforced by `RequireReasonMetadata` middleware.
- Missing reasons trigger RFC 7807 `403 FORBIDDEN` problem details.

### 3. Append-Only Immutable Audit Trail (`audit_events`)
- Managed by `AuditLogger::log()`.
- Captures: `trace_id`, `actor_id`, `actor_type`, `subject_type`, `subject_id`, `action`, `purpose`, `ip_hash`, and structured `metadata`.
- Multi-layer immutability controls:
  1. **Application-Layer Protection:** Eloquent model lifecycle hooks (`static::updating()` and `static::deleting()`) raise `RuntimeException`, failing fast on individual model modification attempts. No `updated_at` column is defined (`const UPDATED_AT = null`).
  2. **Database Engine-Layer Protection:** MySQL 8.4 LTS InnoDB `BEFORE UPDATE` and `BEFORE DELETE` triggers (`audit_events_prevent_update`, `audit_events_prevent_delete`) raise `SQLSTATE '45000'`, aborting all DML statements—including query-builder mass updates/deletes and raw SQL execution.
  3. **Operational Environment Security:** MySQL triggers guard against application and operational DML mutations. In production environments, database connection roles should be restricted to `SELECT` and `INSERT` grants on the `audit_events` table to prevent administrative DDL overrides (`DROP TABLE`, `TRUNCATE TABLE`). Strict physical immutability is guaranteed when combining engine triggers with least-privilege database user permissions.
