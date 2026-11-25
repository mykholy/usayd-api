# Health probes / جاهزية الخدمة

GET /api/v1/health and /api/health preserve existing response shape. Database failure returns 503; required Redis failure returns degraded status with HTTP 200 under the existing readiness contract. Diagnostics are limited to local and testing with debug enabled. The public problem instance contains only the request path, never its query string. Diagnostic error values contain exception class names rather than raw messages. No production runtime details are exposed even if APP_DEBUG is accidentally enabled.

تعرض الخدمة حالة الاتصال دون كشف بيانات الاتصال أو معاملات الرابط. تظهر تفاصيل التشخيص فقط في بيئة التطوير والاختبار.
