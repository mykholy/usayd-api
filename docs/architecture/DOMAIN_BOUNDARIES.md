# Domain Boundaries and Modular Monolith Guidelines

> **Ticket Reference:** USYD-T0022  
> **Applies to:** `usayd-api`  

---

## 1. Bounded Context Catalogue

The Usayd Academy Backend is organized into 11 bounded contexts under `app/Modules/`:

```
app/Modules/
├── Identity       # Auth, tokens, MFA, sessions, RBAC/ABAC, user accounts
├── Programs       # Curriculum, courses, levels, academic tracks
├── Booking        # Teacher availability, slot reservations, bookings, DST rules
├── Classroom      # Live video sessions, attendance, room provider adapters
├── Learning       # Assessments, student learning plans, memorization progress
├── Payments       # Quotes, checkout, billing, refunds, webhook verification
├── Supervision    # Supervisor observation, evaluations, quality audits
├── Guardian       # Child safety, guardian consent ledger, minor limited mode
├── Quran          # Licensed Quran text data, ayahs, recitation metadata (read-only)
├── Payroll        # Teacher contracts, wage calculations, pay runs
└── Competitions   # Contests, scoring, rankings, appeals
```

---

## 2. Standard Module Directory Structure

Every module adheres to a predictable 4-layer structure:

```
app/Modules/<ModuleName>/
├── Contracts/       # Public Interfaces, DTOs, and Events exposed to other modules
├── Domain/          # Core business logic, Entities, Value Objects, Domain Exceptions
├── Infrastructure/  # Eloquent Models, Database Repositories, Third-Party Adapters
└── Http/            # REST Controllers, Form Requests, JSON API Resources
```

---

## 3. Communication Invariants

1. **Contract-Only Dependency Rule:**
   - Module `X` may only import symbols from Module `Y\Contracts`.
   - Importing `Y\Domain` or `Y\Infrastructure` directly from outside Module `Y` is forbidden.
2. **Database Boundary:**
   - Tables owned by Module `X` must not be joined in SQL queries written inside Module `Y`.
   - Data sharing occurs via Domain Events or explicit Contract Service calls.
3. **External Client Contract:**
   - Mobile and Web clients communicate strictly via HTTP REST `/api/v1` operations defined in the authoritative OpenAPI specification (`openapi.yaml`).
   - Cross-repository source code sharing or relative paths (`../usayd-web`, `../usayd-flutter`) are prohibited.
