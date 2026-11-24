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

All mutating API endpoints (`POST`, `PUT`, `PATCH`, `DELETE`) in Usayd Academy support the IETF `Idempotency-Key` HTTP header.

### 2.1. Middleware Lifecycle (`EnforceIdempotency`)
1. **Header Validation:** The client passes a unique `Idempotency-Key` (1–255 ASCII printable characters).
2. **Payload Hashing:** The middleware generates a deterministic SHA256 digest: `hash('sha256', method + '|' + path + '|' + raw_payload)`.
3. **Atomic Reservation (`in_flight`):** An atomic `SELECT ... FOR UPDATE` or insert is performed on `idempotency_keys`:
   - **New Key:** A record is inserted with `status: 'in_flight'` and `expires_at: now() + 24 hours`. The request proceeds to the controller.
   - **Duplicate Key (Completed):** The cached response (status code, headers, JSON body) is returned immediately with `Idempotent-Replayed: true`. The controller is not invoked.
   - **Duplicate Key (In-Flight):** An RFC 7807 `409 Conflict` problem details response is returned: `"A request with this Idempotency-Key is currently being processed"`.
   - **Key Reused with Mismatched Payload:** An RFC 7807 `422 Unprocessable Entity` is returned: `"This Idempotency-Key was previously used with a different request payload"`.
4. **Completion:** On controller completion, the record transitions to `status: 'completed'` and caches the response. On uncaught exception, it transitions to `status: 'failed'` allowing client retry.

---

## 3. Test Evidence & Verification
- `tests/Feature/Concurrency/IdempotencyMiddlewareTest.php`: Verifies initial execution, replay deduplication, 422 payload mismatch, 409 in-flight conflict, and 400 format errors.
- `tests/Feature/Concurrency/DeadlockRetryTest.php`: Proves automatic retry on simulated MySQL deadlock.
- `tests/Feature/Concurrency/RowLevelLockingTest.php`: Verifies serialized atomic balance updates without race conditions.
- `tests/Feature/Concurrency/ParallelProcessConcurrencyTest.php`: Executes real parallel PHP worker processes against MySQL 8.4 to prove zero lost updates.
