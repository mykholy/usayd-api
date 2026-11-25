# Backend engineering standard — incremental adoption

## Dependency rules
- Business modules live under app/Modules. HTTP owns controllers, FormRequests, Resources and routes; Application owns use cases, DTOs and ports; Domain owns framework-independent rules; Infrastructure implements persistence and providers. Public cross-module calls target Contracts.
- New controllers orchestrate a single use case, not database, cache, external integrations or audit logic. Production routes declare routing only.
- Use interfaces at genuine boundaries, not generic BaseRepository/Service classes. Avoid pass-through abstractions where no boundary exists.
- Legacy Eloquent models and Laravel Gate remain transitional in the first Learning slice; migrate them to module-owned adapters and explicit authorization context in later slices.
- State mutation and mandatory audit use one transaction. Idempotent financial mutations require durable operation identity and atomic recovery.
- Preserve MySQL 8.4, Redis, Sanctum, OpenAPI and current clients.

## Localization
- Client-facing human text uses lang/ar and lang/en keys; stable codes, JSON keys, enum values, audit actions and machine identifiers never translate.
- Select locale before validation using Accept-Language; Arabic is default. Emit Content-Language and Vary. Add both languages for every new translation key. Never log secrets.
- Existing literal custom exceptions and success strings must be migrated in subsequent slices. Arabic validation catalogue initially covers current API rules; extend as rules are introduced.
- Test both locales and consecutive requests.

## Verification
- Every PR: Pint, architecture scanner, real MySQL/Redis suite, OpenAPI validation, negative security tests and authorized review. No fabricated test results. CI gates must fail closed.
- Follow-up: migrate Identity, storage ownership, feature flags, observability, idempotency, outbox; introduce compatible PHPStan/Larastan and dependency analysis; remove transitional dependencies and expand scanner coverage to every layer.
- Update API documentation and OpenAPI on each public contract change. Never merge with pending/failed required checks.

### Enforced layer dependency guard
The architecture verification script now checks qualified references (including grouped imports, aliases, and fully-qualified names) for Domain, Application and Contracts under Modules and Platform. Domain cannot import Application or framework/infrastructure code; Application cannot import HTTP or infrastructure; Contracts must not expose own internal types. Cross-module internal references are also checked inside Contracts. Existing Learning Eloquent references and FeatureFlags application persistence/facade references are explicitly baselined per file and symbol; do not expand this baseline. The lexical scanner is not a substitute for PHPStan/Larastan or Deptrac; these remain follow-up dependency work. Negative fixtures verify the guard actually rejects violations.
