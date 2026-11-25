# API documentation

- `implemented.openapi.json`: OpenAPI 3.1 for the currently registered public HTTP API scaffold; import directly into Swagger Editor or Postman. This is not a deployment or readiness claim.
- `IDENTITY.md`: auth modes, response envelopes, error behavior, and known gaps.
- `LOCALIZATION.md`: Arabic/English negotiation and stable machine codes.
- `../../openapi.yaml`: intended product contract with planned endpoints. Do not mark future endpoints implemented based on the draft.

The implemented specification is checked against Laravel's registered API routes by a feature test. The CI script validates the JSON document, unique operation IDs and local references. This structural check does not replace a full OpenAPI schema validator or runtime response contract tests.

العربية: ملف implemented.openapi.json يصف المسارات المسجلة فعليا في التطبيق. ملف openapi.yaml الرئيسي يتضمن مسارات مخططا لها ولا يثبت جاهزية التنفيذ. استورد الملف في Swagger أو Postman لاستخدامه في التكامل.
