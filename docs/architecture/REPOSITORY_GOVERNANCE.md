# Usayd Academy — Repository Governance & Permissions Architecture

> **Ticket:** USYD-T0021  
> **Epic:** USYD-E-FOUNDATION  
> **Classification:** Engineering Governance  

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

---

## 3. CODEOWNERS and Approval Gates

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

## 4. Automation and CI Verification Gates

1. **API Quality CI (`ci.yml`):**
   - Syntax & formatting: `laravel pint --test`
   - Static analysis: PHPStan / Larastan
   - Unit & integration tests: Pest PHP backed by real MySQL 8.4 and Redis
   - Schema validation: `check-openapi.sh`
2. **AI Code Review Gate (`ai-review.yml` / `pr_review.py`):**
   - Reviews diff against acceptance criteria, security invariants, and performance standards.
   - Advisory check only; cannot substitute for human approval.
3. **Safe Auto-Merge (`safe-automerge.yml`):**
   - Applies only to low-risk changes (documentation, non-sensitive fixtures) with 100% green checks and zero blocking findings.
