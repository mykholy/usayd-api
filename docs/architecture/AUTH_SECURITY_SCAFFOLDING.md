# Architecture Specification: Authentication & Security Scaffolding

## Status
- **Status:** Approved & Implemented
- **Date:** 2026-11-24
- **Owning Component:** `usayd-api`
- **Issue Reference:** `USYD-T0028` (Sprint S01)

## Context & Architecture
Usayd Academy serves two primary client platforms with distinct authentication characteristics:
1. **First-Party Web SPA (Next.js):** Consumes API via stateful HTTP-only session cookies and CSRF token verification.
2. **First-Party Mobile App (Flutter):** Consumes API via Bearer access tokens with rotating refresh tokens stored in secure OS keychains (`flutter_secure_storage`).

## Key Security Controls & Invariants

### 1. Dual-Boundary Authentication Architecture
- **Web SPA Mode:**
  - Route `/sanctum/csrf-cookie` initiates session and issues `XSRF-TOKEN` cookie.
  - Subsequent stateful API requests pass encrypted session cookies with `X-XSRF-TOKEN` header.
  - Handled via `EnsureFrontendRequestsAreStateful` and `cors.php` configured with `supports_credentials => true`.
- **Mobile Token Mode:**
  - Registered via `POST /api/v1/auth/register` or authenticated via `POST /api/v1/auth/login`.
  - Returns `AuthTokenEnvelope`:
    - `access_token`: Short-lived (60 minutes), granted ability `access-api`.
    - `refresh_token`: Long-lived (30 days), granted ability `issue-token`.
    - `expires_in`: 3600 seconds.
    - `token_type`: `Bearer`.

### 2. State-Backed Single-Use Refresh Token Rotation
- Endpoints: `POST /api/v1/auth/refresh`.
- **No guessed JWT refresh policies:** Refresh tokens are tracked in `personal_access_tokens` indexed by hash.
- Upon each refresh request:
  1. The presented refresh token is validated for expiration and `issue-token` capability.
  2. The consumed refresh token is **immediately destroyed** (revoked).
  3. A new access token and new refresh token pair is minted.
  4. Attempting to replay an already-consumed refresh token immediately fails with RFC 7807 `401 UNAUTHORIZED`.

### 3. Rate Limiting & Brute-Force Protection
- Login attempts are throttled by composite key `login:{email}|{ip}` using Laravel's Redis/Cache RateLimiter.
- Max 5 failed attempts per 60 seconds.
- 6th attempt triggers RFC 7807 `429 RATE_LIMITED` response carrying `retryAfter` extension.
- Emits `auth.login.throttled` audit event.

### 4. Token & Session Revocation
- `POST /api/v1/auth/logout`:
  - Default: Revokes current device token.
  - `all_devices: true`: Revokes all active tokens across all devices for the account.
  - For web sessions: Invalidates HTTP session and regenerates CSRF token.

### 5. Audit Logging Integration
All sensitive authentication lifecycle events are recorded in `audit_events`:
- `auth.register.success`
- `auth.login.token` / `auth.login.spa`
- `auth.login.failed`
- `auth.login.throttled`
- `auth.token.rotated`
- `auth.logout`
