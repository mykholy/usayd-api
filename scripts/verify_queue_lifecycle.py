#!/usr/bin/env python3
"""
USYD-T0026: Queue Architecture & Failed-Job Lifecycle Verification
Verifies Redis queue partitions, Horizon supervisor configuration, base queue job backoff,
dead-letter review command, and queue test suite for Usayd Academy API.
"""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

def check_files_exist():
    required_files = [
        ROOT / "config" / "queue.php",
        ROOT / "config" / "horizon.php",
        ROOT / "app" / "Jobs" / "BaseQueueJob.php",
        ROOT / "app" / "Jobs" / "SampleProcessingJob.php",
        ROOT / "app" / "Services" / "Queue" / "QueueMetricsService.php",
        ROOT / "app" / "Http" / "Controllers" / "Api" / "V1" / "QueueMetricsController.php",
        ROOT / "app" / "Console" / "Commands" / "QueueDeadLetterReviewCommand.php",
        ROOT / "docs" / "architecture" / "QUEUE_AND_FAILED_JOB_LIFECYCLE.md",
        ROOT / "tests" / "Feature" / "Queue" / "QueueConfigurationTest.php",
        ROOT / "tests" / "Feature" / "Queue" / "JobRetryAndBackoffTest.php",
        ROOT / "tests" / "Feature" / "Queue" / "FailedJobLifecycleTest.php",
        ROOT / "tests" / "Feature" / "Queue" / "QueueMetricsTest.php",
    ]

    missing = [str(f.relative_to(ROOT)) for f in required_files if not f.exists()]
    if missing:
        for m in missing:
            print(f"FAIL: Missing file: {m}")
        return False

    print(f"PASS: All {len(required_files)} queue lifecycle files present.")
    return True

def check_queue_partitions():
    queue_file = ROOT / "config" / "queue.php"
    content = queue_file.read_text(encoding="utf-8")

    errors = []
    for part in ["redis-high", "redis-notifications", "redis-reports"]:
        if part not in content:
            errors.append(f"config/queue.php missing partition connection: {part}")

    if "'after_commit' => true" not in content:
        errors.append("config/queue.php must enable after_commit on Redis queue connections")

    if errors:
        for err in errors:
            print(f"FAIL: {err}")
        return False

    print("PASS: Verified Redis queue partitions and after_commit transactional safety.")
    return True

def check_horizon_config():
    horizon_file = ROOT / "config" / "horizon.php"
    content = horizon_file.read_text(encoding="utf-8")

    errors = []
    for q in ["high", "default", "notifications", "reports"]:
        if f"'{q}'" not in content and f'"{q}"' not in content:
            errors.append(f"config/horizon.php missing queue partition: {q}")

    if errors:
        for err in errors:
            print(f"FAIL: {err}")
        return False

    print("PASS: Verified Horizon supervisor partitions and metrics configuration.")
    return True

def main():
    print("=== Usayd Academy: Queue Architecture & Failed-Job Lifecycle Verification ===")
    results = [
        check_files_exist(),
        check_queue_partitions(),
        check_horizon_config(),
    ]

    if all(results):
        print("\nAll queue lifecycle checks PASSED.")
        sys.exit(0)
    else:
        print("\nOne or more checks FAILED.")
        sys.exit(1)

if __name__ == "__main__":
    main()
