#!/usr/bin/env python3
"""
USYD-T0024: Database Foundations Verification Script
Verifies MySQL 8.4 LTS configuration, InnoDB engine strictness, utf8mb4 charset,
symmetrical rollback migrations, and absence of PostgreSQL-specific syntax.
"""

import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

FORBIDDEN_POSTGRES = [
    (r"\bILIKE\b", "PostgreSQL ILIKE operator"),
    (r"::jsonb?\b", "PostgreSQL type casting (::json/::jsonb)"),
    (r"\bRETURNING\b", "PostgreSQL RETURNING clause in DML"),
    (r"\bgen_random_uuid\(\)", "PostgreSQL gen_random_uuid() function"),
    (r"\bnow\(\)\s+at\s+time\s+zone\b", "PostgreSQL AT TIME ZONE syntax"),
    (r"\bserial\s+primary\s+key\b", "PostgreSQL SERIAL keyword"),
    (r"\bbytea\b", "PostgreSQL BYTEA type"),
]

def check_config_database():
    config_file = ROOT / "config" / "database.php"
    if not config_file.exists():
        print(f"FAIL: {config_file} missing")
        return False

    content = config_file.read_text(encoding="utf-8")
    errors = []

    if "'default' => env('DB_CONNECTION', 'mysql')" not in content:
        errors.append("Default DB connection must be 'mysql'")

    if "'engine' => 'InnoDB'" not in content:
        errors.append("MySQL engine must explicitly be 'InnoDB'")

    if "'strict' => true" not in content:
        errors.append("MySQL strict mode must explicitly be true")

    if "utf8mb4" not in content or "DB_CHARSET" not in content:
        errors.append("MySQL charset must be 'utf8mb4'")

    if "utf8mb4_unicode_ci" not in content or "DB_COLLATION" not in content:
        errors.append("MySQL collation must be 'utf8mb4_unicode_ci'")

    for mode in [
        "ONLY_FULL_GROUP_BY",
        "STRICT_TRANS_TABLES",
        "NO_ZERO_IN_DATE",
        "NO_ZERO_DATE",
        "ERROR_FOR_DIVISION_BY_ZERO",
        "NO_ENGINE_SUBSTITUTION",
    ]:
        if mode not in content:
            errors.append(f"MySQL strict modes missing required mode: {mode}")

    if errors:
        for err in errors:
            print(f"FAIL: {err}")
        return False

    print("PASS: config/database.php strict MySQL 8.4 configuration verified.")
    return True

def check_migrations():
    migrations_dir = ROOT / "database" / "migrations"
    if not migrations_dir.exists():
        print(f"FAIL: {migrations_dir} missing")
        return False

    migration_files = sorted(migrations_dir.glob("*.php"))
    if not migration_files:
        print("FAIL: No migration files found")
        return False

    errors = []
    for mf in migration_files:
        content = mf.read_text(encoding="utf-8")

        # Verify down() exists and is not empty
        down_match = re.search(r"public function down\(\): void\s*\{([^}]+)\}", content, re.DOTALL)
        if not down_match:
            errors.append(f"{mf.name}: Missing or non-void down() method")
        else:
            down_body = down_match.group(1).strip()
            if not down_body or "//" in down_body and len(down_body.splitlines()) <= 1:
                errors.append(f"{mf.name}: Empty down() rollback method")

        # Verify no PostgreSQL syntax
        for pattern, desc in FORBIDDEN_POSTGRES:
            if re.search(pattern, content, re.IGNORECASE):
                errors.append(f"{mf.name}: Contains forbidden PostgreSQL syntax: {desc}")

    if errors:
        for err in errors:
            print(f"FAIL: {err}")
        return False

    print(f"PASS: Verified {len(migration_files)} migrations for rollback and MySQL compliance.")
    return True

def check_no_postgres_in_codebase():
    scan_dirs = [ROOT / "app", ROOT / "config"]
    errors = []

    for d in scan_dirs:
        for php_file in d.rglob("*.php"):
            content = php_file.read_text(encoding="utf-8")
            for pattern, desc in FORBIDDEN_POSTGRES:
                if re.search(pattern, content, re.IGNORECASE):
                    errors.append(f"{php_file.relative_to(ROOT)}: Contains forbidden PostgreSQL syntax: {desc}")

    if errors:
        for err in errors:
            print(f"FAIL: {err}")
        return False

    print("PASS: Verified no PostgreSQL syntax across app/ and config/ codebases.")
    return True

def main():
    print("=== Usayd Academy: Database Foundations Verification ===")
    results = [
        check_config_database(),
        check_migrations(),
        check_no_postgres_in_codebase(),
    ]

    if all(results):
        print("\nAll database foundations checks PASSED.")
        sys.exit(0)
    else:
        print("\nOne or more database foundations checks FAILED.")
        sys.exit(1)

if __name__ == "__main__":
    main()
