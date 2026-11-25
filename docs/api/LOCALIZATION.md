# API localization / ترجمة واجهة البرمجة

Send `Accept-Language: ar` or `Accept-Language: en` (regional variants supported). Arabic is the default. Responses from the API locale middleware include `Content-Language` and `Vary: Accept-Language`. Clients must branch on stable `code`, HTTP status and field names, never translated text.

أرسل ترويسة Accept-Language لاختيار العربية أو الإنجليزية. العربية هي اللغة الافتراضية. لا تعتمد في منطق التطبيق على نص الرسالة، بل على رمز الخطأ وحالة HTTP.

Example: POST /api/v1/auth/login with an empty JSON body returns 422, code VALIDATION_FAILED, and localized field errors under errors and invalidParams.

The first slice localizes validation, generic problem details and selected authorization messages. Remaining custom legacy messages are tracked for migration; do not assume every endpoint has complete localization yet.

The existing root openapi.yaml remains the authoritative intended contract. It includes future operations; presence in the spec is not proof of deployment.
