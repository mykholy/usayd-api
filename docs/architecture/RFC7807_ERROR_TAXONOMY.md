# Architecture Specification: RFC 7807 / RFC 9457 Problem Details Error Taxonomy & Localization

## Status
- **Status:** Approved & Implemented
- **Date:** 2026-11-24
- **Owning Component:** `usayd-api`
- **Issue Reference:** `USYD-T0027` (Sprint S01)

## Context & Objectives
Usayd Academy provides mobile apps (Flutter) and web applications (Next.js) consuming the central Laravel API.
To achieve reliable client error handling, uniform monitoring, and clear localization (Arabic first, English supported), the API enforces RFC 7807 (and RFC 9457) Problem Details for all HTTP 4xx and 5xx responses.

## Key Principles & Invariants
1. **Content-Type Contract:**
   All error responses must carry `Content-Type: application/problem+json`.
2. **Deterministic Request Tracing:**
   Every request is tagged with a trace identifier (`traceId`), propagated through:
   - Request attributes: `$request->attributes->get('traceId')`
   - Response headers: `X-Request-ID` and `X-Trace-ID`
   - JSON payload field: `traceId`
   If an incoming request includes `X-Request-ID`, `X-Trace-ID`, or W3C `traceparent`, that ID is validated and preserved. Otherwise, a UUIDv4 is generated.
3. **Canonical Error Codes:**
   Standardized enum-like error codes:
   - `UNAUTHORIZED` (401)
   - `FORBIDDEN` (403)
   - `RESOURCE_NOT_FOUND` (404)
   - `METHOD_NOT_ALLOWED` (405)
   - `CONFLICT` (409)
   - `VALIDATION_FAILED` (422)
   - `RATE_LIMITED` (429)
   - `BAD_REQUEST` (400)
   - `INTERNAL_ERROR` (500)
4. **Bilingual Localization (AR / EN):**
   - The primary locale is Arabic (`ar`), reflecting the academy's target audience.
   - Evaluated from `Accept-Language` header (`en` or `ar`).
   - Titles and details are loaded from `lang/ar/errors.php` and `lang/en/errors.php`.
5. **Zero Secret / Stack Trace Leakage:**
   When `APP_DEBUG=false`, internal server errors (`500`) sanitize all messages to generic user-friendly text while logging the underlying exception with the `traceId` for administrative investigation.
6. **Detailed Validation Errors:**
   Validation errors (HTTP 422) return field-level issues under `invalidParams` and `errors`.

## JSON Schema Example (RFC 7807)
```json
{
  "type": "https://api.usayd.academy/errors/VALIDATION_FAILED",
  "title": "بيانات الإدخال غير صالحة",
  "status": 422,
  "detail": "فشل التحقق من صحة حقول الإدخال المقدمة.",
  "instance": "/api/v1/auth/register",
  "code": "VALIDATION_FAILED",
  "traceId": "9b81206c-8e43-4fa8-a3f2-1d6cfb33b91a",
  "invalidParams": {
    "email": ["حقل البريد الإلكتروني مطلوب."]
  }
}
```

## Architectural Components
- `App\Http\Middleware\AssignTraceId`: Global middleware for request correlation.
- `App\Exceptions\ApiException`: Extensible base exception for domain and API errors.
- `App\Support\Http\ProblemDetailsResponse`: Formatter transforming exceptions to RFC 7807 responses.
- `lang/ar/errors.php` & `lang/en/errors.php`: Canonical localized message dictionaries.
