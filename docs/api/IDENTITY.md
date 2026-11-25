# Identity API — implemented scaffold / واجهة الهوية

Base path: /api/v1. Send Accept-Language: ar or en. Use secure HTTPS. All errors use Problem Details; do not branch on translated messages.

| Method / route | Request | Success |
|---|---|---|
| POST /auth/register | name, email, password (min 8), optional phone, locale, device_name | 201 authentication envelope |
| POST /auth/login | email, password, optional device_name | 200 authentication envelope |
| POST /auth/refresh | refresh_token, optional device_name | 200 rotated token envelope |
| GET /me or /auth/me | access token or first-party session | 200 profile |
| POST /auth/logout | access token or session, optional all_devices | 200 status and localized message |

Mobile: response data has access_token, refresh_token, expires_in, token_type. Access and refresh tokens must remain in secure storage. Web: obtain CSRF cookie first; authenticated session response contains data.user and data.session_authenticated. Never persist refresh token in logs.

401 invalid credentials/token or inactive account; 403 wrong token ability; 422 validation; 429 throttling with Retry-After. Login and refresh refuse non-active accounts. Registration defaults are configurable, preserving existing EG/Africa/Cairo behavior pending product decision. This does not implement guardian consent, MFA, full session revocation across devices or physical token-family database columns.

العربية: استخدم الترويسة Accept-Language: ar. تسجيل الدخول وإعادة إصدار الرموز متاحان للحسابات النشطة فقط. يجب حفظ الرموز بشكل آمن وعدم تسجيلها في السجلات.

The original OpenAPI is a future contract draft; the currently implemented email-based scaffold is documented separately. Do not treat draft contact/age-band registration as implemented.

Token lifecycle: each issuance has a server-generated independent family ID even when device labels coincide. Existing device-name-only token pairs remain accepted and are deterministically migrated on refresh so in-flight logout can revoke the replacement; existing indistinguishable legacy pairs are revoked as one family. Expired refresh tokens are removed on rejection. Previously issued access tokens for inactive accounts are rejected on protected API routes. Mobile account creation, audit and token issuance are atomic. Web session establishment occurs after the database transaction commits.
