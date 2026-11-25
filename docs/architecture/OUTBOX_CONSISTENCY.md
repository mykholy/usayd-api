# Transactional outbox consistency

The relay works on committed outbox rows, locks each selected row and enqueues the job before marking it published, overriding Redis after-commit dispatch for this specific relay. A queue push failure remains retryable. Redis and MySQL do not share a distributed transaction; a crash can still produce duplicate messages. The consumer locks the canonical outbox row and atomically records its internal acknowledgment and audit; missing, unpublished or already acknowledged rows are not processed. The worker checks abandoned processing rows before polling. Replay is limited to dead-letter rows and the audit commits with the reset.

The existing delivered state and notification_delivered event are compatibility identifiers for the internal consumer scaffold. They are not evidence of email, SMS, push, or third-party delivery; no external provider is configured by this change. A real provider adapter will need a durable delivery ID and provider-specific idempotency. Duplicate recordInTransaction operations roll back their domain callback instead of silently committing a duplicate mutation.

العربية: إرسال المهمة إلى قائمة الانتظار لا يعني تسليم إشعار فعلي للمستخدم. يتم تسجيل إقرار المعالجة الداخلي مرة واحدة داخل معاملة، وتظل إعادة محاولة الإرسال الخارجي مسؤولية التكامل المستقبلي.
