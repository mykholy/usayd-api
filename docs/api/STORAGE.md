# Private storage ownership / ملكية الملفات الخاصة

Signed URLs are issued only for explicitly configured owner-scoped namespaces. The initial namespace is `recitations/{owner_id}/...`; the canonical path owner must equal the authorized owner. Only `private` and `s3_private` disks are permitted. Unknown namespaces fail closed. The client must never infer authorization from a file name. Upload writers must enforce the server-owned namespace and owner ID. Download verifies the signature, expiry and owner binding again, including for pre-existing links.

لا يصدر النظام رابطا موقعا إلا لملف ضمن المسار الخاص بالمالك المخول وعلى وحدة تخزين خاصة. ترفض الروابط التي لا تحتوي على معرف المالك الصحيح. يجب إضافة أي مسارات جديدة فقط بعد تأكيد قواعد ملكية الملفات في منطق رفع الملفات.

This is an interim namespace-based safeguard, not a full asset registry. A future server-owned asset table and upload lifecycle should replace client-supplied paths.

Private downloads are sent as attachments with `application/octet-stream`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, and `X-Robots-Tag: noindex, nofollow, noarchive`. This prevents trusting client-supplied file extensions or object MIME metadata for browser rendering. It does not replace upload MIME validation, malware scanning, a retention schedule, or a verified S3-compatible deployment. Those remain required before USYD-71 can close; retention periods must follow approved purpose/consent policy.

يتم إرسال الملفات الخاصة كمرفقات دون عرض المحتوى داخل المتصفح أو السماح بتخزينه مؤقتاً. لا يغني ذلك عن فحص الملفات عند الرفع وسياسة الاحتفاظ المعتمدة.


## Upload safety boundary (USYD-71)

`App\Platform\Storage\Application\UploadSafetyGate` is a framework-independent pre-persistence guard. The writer must enforce authentication, owner namespace, a server-owned per-purpose byte limit and MIME allowlist **before reading or allocating the upload**, then pass the exact bounded bytes to the guard. Persist those same bytes only after the guard returns a detected MIME type. Never use the client MIME or extension as proof of type. The guard is not a complete file-format parser; specific upload use cases must validate content structure as appropriate.

The `MalwareScanner` port accepts those exact bytes and returns `Clean`, `Infected` or `Unavailable`. All non-clean results and scanner exceptions reject the upload. The infrastructure binding now uses ClamAV through a shell-free, bounded subprocess with stdin. Missing scanner, errors and timeouts fail closed; no success-by-default binding exists. Do not expose an upload route or persist user files before ownership authorization, localized error mapping, use-case-specific upload writer tests and production scanner signature freshness are in place. The upload gate unit tests use synthetic bytes and scanner test doubles. A separate CI test invokes the actual ClamAV engine with an isolated synthetic signature database, not production signature coverage.

Stable internal rejection reasons: `upload_size_invalid`, `upload_content_type_denied`, `upload_malware_detected`, `upload_scan_unavailable`. A future HTTP boundary must map these to localized, non-revealing problem details without returning scanner diagnostics or file bytes. No public endpoint or OpenAPI operation is introduced here.

### Retention and S3 deployment — conditional owner decision, 2026-11-26

AWS private S3 is the selected infrastructure direction. The project owner accepted proposed retention of lesson recordings (30 days), AI audio (7 days), and security logs (90 days), **subject to legal/DPO approval**. These categories and durations do not authorize processing, automatic deletion, or applying one TTL to every private object. Recording and AI audio collection remain off by default; require purpose-specific explicit consent and approval. Security-log retention belongs to the logging lifecycle, not a blanket S3 object lifecycle.

The official Flysystem AWS adapter is installed with a Composer-generated lockfile. CI requires real round trips against a loopback SeaweedFS instance, denied anonymous reads and actual ClamAV engine checks. Before production: verify AWS bucket/IAM/encryption and object ownership settings (including ACL compatibility), region, signature freshness/updates, bounded upload writer and consent enforcement; obtain legal/DPO retention sign-off and document lifecycle exclusions and legal holds. No production AWS credentials, bucket, lifecycle rules or feature flags changed. The S3-compatible CI service is not proof of AWS account readiness.


### Local verification and production boundary

Run on an isolated environment only: `composer install`; `composer validate --strict`; `USAYD_REQUIRE_S3_TESTS=1 USAYD_REQUIRE_CLAMAV_TESTS=1 USAYD_REQUIRE_REDIS_TESTS=1 php artisan test`. The S3 test requires SeaweedFS on loopback port 9000 using disposable CI fixture credentials defined in the test; it creates and removes its own unique bucket. ClamAV tests require `/usr/bin/clamscan` and use an isolated synthetic signature database. CI starts/stops SeaweedFS and installs the ClamAV engine; no production resources are contacted.

Configure `AWS_PRIVATE_BUCKET` explicitly; there is no fallback to the generic/public bucket. Use workload IAM credentials and block all public access; leave endpoint override unset on AWS. Default S3 region is only a configuration fallback, not the approved data-residency region. Confirm actual AWS bucket ownership/ACL policy compatibility and encryption before enabling any uploader. Production ClamAV must have an up-to-date, trusted signature database maintained outside the request process. The adapter is intended for bounded workloads, not large unbounded recordings.
