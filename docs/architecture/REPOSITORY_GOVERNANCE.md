# Usayd Academy — Repository Governance & Permissions Architecture

> **Ticket:** USYD-T0021 / Governance Repair  
> **Epic:** USYD-E-FOUNDATION  
> **Classification:** Engineering Governance & Safety Gates  

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

## 3. Governance Gates & Verification Triad

To ensure full accountability and safety, three distinct review layers are enforced:

| Gate | Mechanism | Purpose | Authority |
|---|---|---|---|
| **1. Automated CI Checks** | GitHub Actions (`ci.yml`) | Validates test execution, syntax, linting, database migrations, and contracts on real MySQL 8.4 + Redis. | Machine gate (hard requirement) |
| **2. Independent AI Review** | `ai-review.yml` / `pr_review.py` | Advisory code quality analysis, diff size checking, secret scanning. | Advisory feedback only (**NEVER counts as approval**) |
| **3. Genuine Human Approval** | `human-review-gate.yml` / CODEOWNERS | Verification of business logic, safety, minor protection, and data integrity by authorized leads. | Human gate (mandatory for sensitive changes) |

### Non-Negotiable Review Invariants
1. **AI Review Never Approves:** An AI review (whether passed, inconclusive, failed, or unavailable) **never** constitutes human approval.
2. **Fail-Closed on Unconfigured Review:** If `GEMINI_API_KEY` or `ALLOW_EXTERNAL_AI_REVIEW` is not configured, the review is marked `UNAVAILABLE`. It never auto-approves.
3. **No Phantom Passes:** CI checks must never simulate or fake success. During pre-bootstrap phases, CI executes structural and governance validations; once `composer.json` is committed, full Laravel + MySQL test suites are strictly enforced.

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
- Auth, checkout, guardian flows, and recording pipelines require explicit human approval.

---

## 5. Branch Protection Plan Constraint & Auto-Merge Policy

- **Current Plan Limitation:** Native GitHub branch protection rules require GitHub Pro/Team or public repository visibility. On personal private repositories, GitHub API returns `HTTP 403`.
- **Enforcement Decision:** **Unattended auto-merging is strictly disabled** (`safe-automerge.yml` fails closed).
- **Manual Verification Required:** Every PR must be verified against actual CI runs, and PRs requiring independent approval must be submitted and held for genuine human sign-off before merging.
