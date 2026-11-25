# API localization / ترجمة واجهة البرمجة

Send `Accept-Language: ar` or `Accept-Language: en` (regional variants supported). Arabic is the default. Responses from the API locale middleware include `Content-Language` and `Vary: Accept-Language`. Clients must branch on stable `code`, HTTP status and field names, never translated text.

أرسل ترويسة Accept-Language لاختيار العربية أو الإنجليزية. العربية هي اللغة الافتراضية. لا تعتمد في منطق التطبيق على نص الرسالة، بل على رمز الخطأ وحالة HTTP.

Example: POST /api/v1/auth/login with an empty JSON body returns 422, code VALIDATION_FAILED, and localized field errors under errors and invalidParams.

The current scaffold has catalog entries for authentication, authorization, storage errors, feature flags, health, idempotency and current validation rules. Audit action names, diagnostic logs, machine identifiers and user-entered data are intentionally not translated. Future validation rules and endpoints must add bilingual messages and tests.

The existing root openapi.yaml remains the intended future contract. The separately maintained implemented.openapi.json records the currently registered public API routes only. Presence in either document is not proof of successful CI or deployment.
