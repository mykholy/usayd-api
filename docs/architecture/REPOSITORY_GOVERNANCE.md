# Usayd Academy — Repository Governance & Permissions Architecture (V2)

> **Ticket:** USYD-T0021 / Governance Repair V2  
> **Epic:** USYD-E-FOUNDATION  
> **Classification:** Engineering Governance, Automated Quality Gates & Merge Safety  
> **Authorization Status:** Project Owner Authorized Autonomous Delivery V2  

---

## 1. Multi-Repository Topology

Usayd Academy strictly enforces a **three-independent-repository architecture**. Cross-repository code copying or monorepo collapse is forbidden.

```
Usayd Academy Workspace
├── usayd-api       -> Laravel 13, PHP >=8.3, MySQL 8.4 LTS, Redis/Horizon
├── usayd-web       -> Next.js (App Router), TypeScript, Tailwind, RTL AR/EN
└── usayd-flutter   -> Flutter/Dart (Android & iOS), Offline-First Sync
```

### Shared Contracts
- API contracts are authored in `usayd-api` (`openapi.yaml`).
- Changes must undergo technical review and backward-compatibility validation.
- Consumer repositories (`usayd-web`, `usayd-flutter`) consume generated, typed SDKs pinned to explicit release SHAs.

---

## 2. Branch and Remote Policies

- **Default Branch:** `main` (protected from direct pushes and force pushes).
- **Feature Branch Pattern:** `feat/USYD-T####-short-slug` or `fix/USYD-T####-short-slug`.
- **Commit Standards:** Conventional Commits (`feat:`, `fix:`, `chore:`, `test:`, `docs:`).
- **Pull Request Rules:** Every change requires a dedicated Pull Request linked to an authoritative task ID.
- **Merge Restrictions:** No PR may be merged when required checks are failing. Unattended CLI merges bypassing check gates are strictly prohibited.

---

## 3. Governance Gates & Verification Triad (V2)

Under **Owner Authorization V2**, repeated manual human approval for standard technical engineering Pull Requests is replaced with an enforceable, fail-closed **Automated Quality Gate V2**:

| Gate | Mechanism | Purpose | Authority |
|---|---|---|---|
| **1. Automated CI Checks** | GitHub Actions (`ci.yml`) | Validates test execution, syntax, linting, database migrations, and contracts on real MySQL 8.4 + Redis. | Machine gate (hard requirement, 0 failures allowed) |
| **2. Independent Code Quality Review** | `ai-review.yml` / `pr_review.py` | Advisory code quality analysis, diff size checking, secret scanning. **Fail-Closed:** Only `PASS` exits 0. `UNAVAILABLE` and `BLOCK` exit 1. | Code inspection gate (fail-closed) |
| **3. Automated Quality Gate V2** | `automated-quality-gate.yml` | Validates task ID, author repository boundary, head commit SHA integrity, and isolates owner-reserved domains. | Owner-authorized policy gate |

### Non-Negotiable Governance Invariants
1. **Fail-Closed Code Review:** If code review encounters credentials missing, model unavailability, or critical findings (`P0`/`P1`), it outputs `UNAVAILABLE` or `BLOCK` and exits with code 1. Green workflow is only emitted for verified `PASS`.
2. **Owner-Reserved Domain Isolation:** Product, financial, legal, academic curriculum theology, licensing, and authentic-content decisions remain strictly owner-reserved (`@mykholy`). Technical implementations do not modify these without explicit owner sign-off.
3. **No Phantom Passes:** CI checks must never simulate or fake success. During pre-bootstrap phases, CI executes structural and governance validations; once `composer.json` is committed, full Laravel + MySQL test suites are strictly enforced.
4. **Deterministic Merge Execution:** PR merges must verify passing CI status checks, current HEAD SHA match, and linear fast-forward/rebase history.

---

## 4. CODEOWNERS and Sensitive Paths

Each repository maintains a `.github/CODEOWNERS` configuration mapping sensitive domains to `@mykholy`:

### usayd-api
- `/app/Modules/Identity/` — Authentication, RBAC, Sessions, MFA
- `/app/Modules/Payments/` — Ledger, checkout, webhook verification
- `/app/Modules/Guardian/` — Minor consent, parental authorization
- `/app/Modules/Payroll/` — Teacher contracts, wage rules
- `/app/Modules/Quran/` — Quran text sourcing & academic authority
- `/app/Modules/AI/` — AI evaluation, voice recordings
- `/database/migrations/` — Database schema evolution
- `/.github/` — CI/CD workflows and security controls

### usayd-web & usayd-flutter
- Auth, checkout, guardian flows, and recording pipelines mapped to `@mykholy`.

---

## 5. Branch Protection Plan Constraint & Auto-Merge Policy

- **Current Plan Limitation:** Native GitHub branch protection rules require GitHub Pro/Team or public repository visibility. On personal private repositories, GitHub API returns `HTTP 403`.
- **Enforcement Decision:** **Unattended native auto-merging is strictly disabled** (`safe-automerge.yml` fails closed).
- **Verified Sequential Merge:** Every PR must be verified against actual CI runs, pass Automated Quality Gate V2 and code review, and be merged via the sequential merge pipeline with HEAD SHA verification.
