# Feature flags / مفاتيح تفعيل المزايا

Admin API routes and response fields are unchanged. The update validates only supported targeting keys; omitted rules are retained, explicit null clears them. Critical flags and the waitlist kill switch consult the database directly rather than stale cache. No default values or owner-reserved feature decisions changed. Error and success messages use existing AR/EN catalogues.

تظل المسارات والحقول كما هي. يتم رفض قواعد الاستهداف غير المعروفة، وتقرأ المزايا الحساسة من قاعدة البيانات مباشرة.
