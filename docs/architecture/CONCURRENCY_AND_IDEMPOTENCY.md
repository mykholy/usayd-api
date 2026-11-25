# Usayd Academy: Concurrency Control & Idempotency Architecture

> **Document Key:** `USYD-ARCH-003` · **Authoritative Spec:** `CODEX_HANDOFF_MASTER.md` · **Related Task:** `USYD-T0025` / `USYD-58`

## 1. Concurrency Control & Isolation

In high-concurrency educational platforms, critical operations such as live slot booking, subscription credit deduction, exam attempt submission, and competition voting are susceptible to race conditions, double booking, and phantom reads.

Usayd Academy enforces the following concurrency standards on **MySQL 8.4 LTS**:

### 1.1. Pessimistic Row-Level Locking (`SELECT FOR UPDATE`)
- Stateful resources (lesson slots, wallets, quota ledgers) must acquire pessimistic row-level exclusive locks inside an explicit database transaction (`DB::transaction(...)`).
- Example:
  ```php
  DB::transaction(function () use ($teacherId, $slotTime) {
      $slot = LessonSlot::where('teacher_id', $teacherId)
          ->where('starts_at_utc', $slotTime)
          ->lockForUpdate()
          ->first();

      if ($slot->status !== 'available') {
          throw new SlotUnavailableException('Slot already held or booked.');
      }

      $slot->update(['status' => 'held', 'hold_expires_at' => now()->addMinutes(10)]);
  });
  ```

### 1.2. Deadlock Detection & Resilient Retries (`ResilientTransaction`)
- Under high concurrent load, competing transactions may deadlock (MySQL Error `1213: ER_LOCK_DEADLOCK`) or experience lock timeouts (MySQL Error `1205: ER_LOCK_WAIT_TIMEOUT`).
- All critical transactions should use `App\Support\Database\ResilientTransaction::run(...)` which intercepts transient deadlocks and retries with exponential backoff and randomized jitter (up to 3 attempts).

---

## 2. API Idempotency Specification

The database-only idempotency middleware supports mutating operations (`POST`, `PUT`, `PATCH`, `DELETE`). It is currently attached only to the test harness; production operations must opt in after confirming authorization, same-connection transaction boundaries, and replay-safe responses.

### 2.1. Middleware lifecycle (atomic database mode)

- Validate key (1–255 printable ASCII characters). Missing keys remain optional in the test harness; production financial mutations must require a key.
- Hash principal, method, target path including query string, and raw body. Reused nonexpired keys with a different request receive 422.
- The unique index and row lock serialize duplicates. Reservation, database mutation, successful JSON snapshot and completed status share one transaction; failures roll back the reservation and mutation. Concurrent duplicates wait for the transaction then replay the committed result; pre-existing ambiguous in-flight reservations receive 409 and require reconciliation, even after expiration.
- Replay preserves the original response locale and sets Idempotent-Replayed. Only replay-safe JSON is accepted; sensitive keys and non-JSON responses fail closed and roll back. Never use this middleware for direct provider calls, authentication token responses, streaming or other irreversible external side effects; use a transactional outbox.
- Existing legacy failed records may retry in the test harness. Reconcile them before enabling production routes. Completed keys expire after 24 hours under the existing policy. This is not a guarantee of exactly-once external delivery.

العربية: يتم تنفيذ العملية المحلية وحفظ نتيجتها داخل معاملة واحدة. يعاد الرد الأصلي بنفس اللغة دون إعادة تنفيذ العملية. يجب استخدام Outbox لأي أثر خارجي وعدم تطبيق هذا المسار على ردود التوكنات.

---

## 3. Test Evidence & Verification
- `tests/Feature/Concurrency/IdempotencyMiddlewareTest.php`: Verifies initial execution, replay deduplication, 422 payload mismatch, 409 in-flight conflict, and 400 format errors.
- `tests/Feature/Concurrency/DeadlockRetryTest.php`: Proves automatic retry on simulated MySQL deadlock.
- `tests/Feature/Concurrency/RowLevelLockingTest.php`: Verifies serialized atomic balance updates without race conditions.
- `tests/Feature/Concurrency/ParallelProcessConcurrencyTest.php`: Executes real parallel PHP worker processes against MySQL 8.4 to prove zero lost updates.
