#!/usr/bin/env python3
"""
USYD-T0025: Concurrency & Idempotency Harness Verification
Verifies presence of idempotency middleware, resilient transaction handler,
custom worker command, and concurrency test suite for Usayd Academy API.
"""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

def check_files_exist():
    required_files = [
        ROOT / "app" / "Http" / "Middleware" / "EnforceIdempotency.php",
        ROOT / "app" / "Support" / "Database" / "ResilientTransaction.php",
        ROOT / "app" / "Console" / "Commands" / "ConcurrencyWorkerCommand.php",
        ROOT / "docs" / "architecture" / "CONCURRENCY_AND_IDEMPOTENCY.md",
        ROOT / "tests" / "Feature" / "Concurrency" / "IdempotencyMiddlewareTest.php",
        ROOT / "tests" / "Feature" / "Concurrency" / "DeadlockRetryTest.php",
        ROOT / "tests" / "Feature" / "Concurrency" / "RowLevelLockingTest.php",
        ROOT / "tests" / "Feature" / "Concurrency" / "ParallelProcessConcurrencyTest.php",
    ]

    missing = [str(f.relative_to(ROOT)) for f in required_files if not f.exists()]
    if missing:
        for m in missing:
            print(f"FAIL: Missing file: {m}")
        return False

    print(f"PASS: All {len(required_files)} concurrency harness files present.")
    return True

def check_middleware_registration():
    bootstrap_file = ROOT / "bootstrap" / "app.php"
    content = bootstrap_file.read_text(encoding="utf-8")

    if "'idempotent'" not in content or "EnforceIdempotency" not in content:
        print("FAIL: 'idempotent' middleware alias not registered in bootstrap/app.php")
        return False

    print("PASS: Middleware alias 'idempotent' verified in bootstrap/app.php.")
    return True

def main():
    print("=== Usayd Academy: Concurrency & Idempotency Harness Verification ===")
    results = [
        check_files_exist(),
        check_middleware_registration(),
    ]

    if all(results):
        print("\nAll concurrency & idempotency harness checks PASSED.")
        sys.exit(0)
    else:
        print("\nOne or more checks FAILED.")
        sys.exit(1)

if __name__ == "__main__":
    main()
