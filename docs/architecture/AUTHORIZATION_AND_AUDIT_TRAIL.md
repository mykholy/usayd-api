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

### 3. Append-Only Audit Trail Scaffolding (`audit_events`) & Boundary Limitations
- Managed by `AuditLogger::log()`.
- Captures: `trace_id`, `actor_id`, `actor_type`, `subject_type`, `subject_id`, `action`, `purpose`, `ip_hash`, and structured `metadata`.
- **Application-Layer Protection:**
  - `static::updating()` throws `RuntimeException` (prohibits Eloquent updates).
  - `static::deleting()` throws `RuntimeException` (prohibits Eloquent deletions).
  - No `updated_at` column (`const UPDATED_AT = null`).
- **Critical Architectural & Database Limitations:**
  - Eloquent model lifecycle hooks intercept individual Eloquent model mutations only. They **do not** intercept mass query-builder calls (`DB::table('audit_events')->update(...)` or `->delete()`) or raw SQL (`UPDATE audit_events ...`).
  - Therefore, application code alone **cannot and does not claim strict physical immutability**.
  - True physical immutability requires infrastructure-level controls: provisioning production database connection credentials with least-privilege grants (`GRANT SELECT, INSERT ON audit_events` while strictly revoking `UPDATE`, `DELETE`, `DROP`, and `ALTER`).
  - Automated test suites verify both application-level enforcement and documented query-builder / SQL boundary limits.
