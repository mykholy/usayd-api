# Usayd Academy: Queue Architecture & Failed-Job Lifecycle

> **Document Key:** `USYD-ARCH-004` · **Authoritative Spec:** `CODEX_HANDOFF_MASTER.md` · **Related Task:** `USYD-T0026` / `USYD-59`

## 1. Overview & Architectural Invariants

Usayd Academy utilizes **Redis 7.4** with **Laravel Horizon** as the primary asynchronous processing engine, backed by **MySQL 8.4** for immutable failed-job retention (`failed_jobs`) and transactional batching (`job_batches`).

### Key Invariants:
1. **Transactional Dispatch (`after_commit => true`):** Jobs are only published to Redis after the database transaction has successfully committed. This eliminates race conditions where workers attempt to fetch records that do not yet exist.
2. **Strict Queue Partitioning:** Workloads are isolated into 4 distinct operational partitions to prevent bulk tasks (e.g. reports or mass emails) from starving real-time tasks (e.g. video slot timeouts or payment webhooks).
3. **Deterministic Exponential Backoff:** All asynchronous jobs inherit from `App\Jobs\BaseQueueJob` and implement exponential backoff: `[5, 15, 60]` seconds with a maximum of 3 attempts.
4. **Dead-Letter Audit Trail:** Terminal job failures (exhausted retries) emit a dead-letter event to `audit_events` and persist the full stack trace and payload in `failed_jobs`.

---

## 2. Queue Partitions

| Partition | Connection | Timeout | Max Tries | Backoff | Intended Workloads |
|---|---|---|---|---|---|
| `high` | `redis-high` | 30s | 3 | [5, 15, 30]s | Slot hold timeouts, OTP delivery, payment webhook reconciliation |
| `default` | `redis` | 90s | 3 | [5, 15, 60]s | Profile updates, calendar sync, achievement badges |
| `notifications` | `redis-notifications` | 60s | 5 | [15, 60, 300]s | Bulk parent emails, SMS notifications, Push alerts |
| `reports` | `redis-reports` | 180s | 3 | [30, 120, 300]s | Lesson report PDF generation, monthly progress reports, analytics |

---

## 3. Horizon Supervisor Configuration (`config/horizon.php`)

Laravel Horizon balances and auto-scales workers across partitions:
- **Balance Strategy:** `auto` (allocates worker processes to queues with highest wait time).
- **Auto-Scaling:**
  - `production`: Min 4 workers, Max 20 workers.
  - `local`: Min 1 worker, Max 5 workers.
  - `testing`: Min 1 worker, Max 2 workers.
- **Trimming Retentions:** Recent jobs retained for 60 minutes; failed jobs retained for 7 days (`10,080` minutes).

---

## 4. Dead-Letter Review & Operational Replay

Administrators and operators can inspect, review, and replay terminal failed jobs via:
1. **CLI Command:**
   ```bash
   # List recent dead-letter jobs
   php artisan usayd:queue-dead-letter --action=list

   # Inspect failed job details and exception trace
   php artisan usayd:queue-dead-letter --action=inspect --id=<UUID>

   # Retry a failed job
   php artisan usayd:queue-dead-letter --action=retry --id=<UUID>
   ```
2. **Metrics API Endpoint:**
   `GET /api/v1/queue/metrics` returns real-time queue partition depths, total failed jobs, and recent error summaries.
