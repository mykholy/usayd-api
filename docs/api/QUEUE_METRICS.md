# Queue metrics / مقاييس قوائم الانتظار

GET /api/v1/queue/metrics accepts the configured operations token or an active admin account authenticated by Sanctum with the access-api ability (including first-party sessions). Refresh-only tokens and other roles are rejected. Failed job summaries are localized, not raw exception strings. If queue depth is unavailable, status is degraded and unavailable_partitions identifies affected queues. Numeric zero for those queues is a compatibility placeholder, not a measured zero.

لا يتم عرض أخطاء التشغيل الخام. تظهر القوائم التي تعذر قياسها في unavailable_partitions وتكون حالة الخدمة degraded.
