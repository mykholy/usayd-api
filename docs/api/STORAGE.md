# Private storage ownership / ملكية الملفات الخاصة

Signed URLs are issued only for explicitly configured owner-scoped namespaces. The initial namespace is `recitations/{owner_id}/...`; the canonical path owner must equal the authorized owner. Only `private` and `s3_private` disks are permitted. Unknown namespaces fail closed. The client must never infer authorization from a file name. Upload writers must enforce the server-owned namespace and owner ID. Download verifies the signature, expiry and owner binding again, including for pre-existing links.

لا يصدر النظام رابطا موقعا إلا لملف ضمن المسار الخاص بالمالك المخول وعلى وحدة تخزين خاصة. ترفض الروابط التي لا تحتوي على معرف المالك الصحيح. يجب إضافة أي مسارات جديدة فقط بعد تأكيد قواعد ملكية الملفات في منطق رفع الملفات.

This is an interim namespace-based safeguard, not a full asset registry. A future server-owned asset table and upload lifecycle should replace client-supplied paths.
