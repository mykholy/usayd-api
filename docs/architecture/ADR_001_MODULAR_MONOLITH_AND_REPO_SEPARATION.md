# ADR 001: Modular Monolith Architecture & Three-Repository Separation

> **Status:** Accepted  
> **Date:** 2026-11-23  
> **Author:** Principal Full Stack Engineer / Codex  
> **Ticket Reference:** USYD-T0022  
> **Epic:** USYD-E-FOUNDATION  

---

## 1. Context and Problem Statement

Usayd Academy delivers an interactive Islamic learning and Quran education platform serving adult learners, children/guardians, teachers, supervisors, and administrative personnel across Web and Mobile applications.

The system requires:
1. Strong domain boundaries to maintain high code cohesion and enforce strict compliance rules (e.g., minor protection, licensed Quran datasets, financial ledger integrity).
2. Strict concurrency control and transaction isolation for booking, slot holding, and payment processing.
3. Separation of deployment and lifecycle between the backend server, web frontend, and native mobile clients.

Should the architecture be structured as microservices, a monolithic application, or a modular monolith? How should repositories be partitioned?

---

## 2. Decision: Modular Monolith in Independent API Repository

We adopt a **Modular Monolith** architecture for the backend API hosted in an independent repository (`usayd-api`), alongside independent repositories for web (`usayd-web`) and mobile (`usayd-flutter`).

### Repository Topology
```
Usayd Academy
├── usayd-api       -> Laravel 13, PHP 8.4, MySQL 8.4 LTS, Redis 7.4
├── usayd-web       -> Next.js 15+ (App Router), TypeScript, Tailwind RTL AR/EN
└── usayd-flutter   -> Flutter (Android & iOS), Dart, Offline Sync
```

1. **No Monorepo Collapse:** The three repositories remain strictly independent with distinct versioning, CI pipelines, and deployment lifecycles.
2. **Versioned OpenAPI as Contract Boundary:** Cross-repo code sharing or relative source imports (`../usayd-web`, `../usayd-flutter`) are strictly prohibited. The authoritative API specification (`openapi.yaml` at `/api/v1`) serves as the single source of truth. Web and Mobile generate and pin typed SDKs to explicit contract SHAs.
3. **Modular Monolith Structure within `usayd-api`:**
   Backend code is organized into decoupled bounded contexts under `app/Modules/`:
   - `Identity`: Authentication, Sessions, RBAC/ABAC, MFA, Device tracking.
   - `Programs`: Academic curricula, tracks, courses, educational levels.
   - `Booking`: Slot holding, booking confirmation, reschedule, cancellation, DST handling.
   - `Classroom`: Video session provider adapters, attendance, incident logging.
   - `Learning`: Assessments, individual learning plans, memory revision maps.
   - `Payments`: Quotes, checkout, webhooks, subscriptions, ledger, refunds.
   - `Supervision`: Supervisor oversight, teacher evaluations, quality metrics.
   - `Guardian`: Minor consent ledger, parental verification, limited mode.
   - `Quran`: Academic Quran text references from licensed sources (read-only authority).
   - `Payroll`: Teacher compensation, pay run generation, dual approvals.
   - `Competitions`: Contests, grading, appeals.

---

## 3. Invariants and Architectural Rules

1. **Database Isolation:**
   - Modules MUST NOT perform cross-boundary SQL joins.
   - Foreign keys across modules are restricted to logical IDs or explicit event references.
   - Concurrency tests MUST run against real MySQL 8.4 InnoDB with row-level locks (`SELECT ... FOR UPDATE`), transaction isolation, and idempotency key enforcement. SQLite is never used as an integration test substitute.
2. **Inter-Module Communication:**
   - Synchronous: Explicit PHP Interface / Service Contracts defined in each module's `Contracts/` namespace.
   - Asynchronous: Domain Events published to the event bus and dispatched via Redis queues.
3. **Transactional Outbox Pattern:**
   - State mutations emitting external events (e.g. notifications, webhook deliveries, financial ledger updates) write to an `outbox_messages` table within the same MySQL database transaction.
4. **Minor Safety & Compliance:**
   - Minor status (`is_minor = true`) enforces limited mode at the API level. No personal data disclosure or direct communication without verified guardian consent.

---

## 4. Consequences and Trade-offs

### Positive
- **Clear Domain Ownership:** Teams and automated agents can work on isolated modules without cascading side effects.
- **Simplified Deployment:** A single deployable backend unit avoids distributed microservices complexity, network latency, and distributed transaction pitfalls (2PC).
- **Strong Concurrency Guarantees:** Local MySQL transactions guarantee atomic seat booking and payment reconciliation.
- **Independent Client Velocity:** Web and Mobile progress independently against stable, versioned OpenAPI contracts.

### Negative & Mitigations
- **Discipline Required:** Developers must avoid cross-module direct database queries.  
  *Mitigation:* Automated architecture boundary tests (`scripts/verify_architecture_boundaries.py`) run in CI to fail any PR introducing illegal cross-domain dependencies.
