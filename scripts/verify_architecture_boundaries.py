#!/usr/bin/env python3
"""
USYD-T0022: Architecture Boundary & Repository Separation Verification Suite
Validates modular monolith boundaries, zero cross-repo relative imports,
domain contract isolation rules, and forbidden cross-module SQL/internal dependencies.
Supports real source code AST/regex scanning and executable negative fixtures.
"""

import os
import sys
import re
import tempfile
import shutil
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]

EXPECTED_MODULES = [
    "Identity",
    "Programs",
    "Booking",
    "Classroom",
    "Learning",
    "Payments",
    "Supervision",
    "Guardian",
    "Quran",
    "Payroll",
    "Competitions",
]

# Patterns for forbidden cross-repo imports (in source code files only, not Markdown)
CROSS_REPO_PATTERNS = [
    re.compile(r"""(?:require|include|import|from)\s+['"][^'"]*\.\./usayd-(?:web|flutter)"""),
    re.compile(r"""['"][^'"]*\.\./usayd-(?:web|flutter)[^'"]*['"]"""),
]

# Table ownership map for checking direct cross-module SQL access
MODULE_TABLES = {
    "Identity": {"users", "roles", "permissions", "sessions", "tokens", "guardians", "audit_events"},
    "Payments": {"invoices", "payments", "transactions", "ledger_entries", "wallets"},
    "Booking": {"bookings", "schedules", "appointments", "slots"},
    "Classroom": {"rooms", "sessions_live", "attendance"},
    "Learning": {"student_progress", "assessments", "curriculum_nodes"},
    "Quran": {"surahs", "ayahs", "recitations", "mushaf_pages"},
    "Payroll": {"payroll_records", "teacher_wages", "payouts"},
}


def test_positive_adr_and_boundary_docs():
    """Verify ADR 001 and Domain Boundaries specifications exist and cover all 11 modules."""
    print("[TEST] test_positive_adr_and_boundary_docs...")
    adr_file = REPO_ROOT / "docs" / "architecture" / "ADR_001_MODULAR_MONOLITH_AND_REPO_SEPARATION.md"
    boundary_file = REPO_ROOT / "docs" / "architecture" / "DOMAIN_BOUNDARIES.md"

    assert adr_file.is_file(), f"ADR 001 missing: {adr_file}"
    assert boundary_file.is_file(), f"Domain boundaries doc missing: {boundary_file}"

    adr_text = adr_file.read_text(encoding="utf-8")
    boundary_text = boundary_file.read_text(encoding="utf-8")

    for mod in EXPECTED_MODULES:
        assert mod in adr_text, f"Module {mod} not documented in ADR 001"
        assert mod in boundary_text, f"Module {mod} not documented in DOMAIN_BOUNDARIES.md"

    print(f"  -> PASS (all {len(EXPECTED_MODULES)} modules documented in ADR and Boundaries)")


def test_positive_zero_cross_repo_imports():
    """Scan all source code files in repository to assert zero illegal relative cross-repo imports."""
    print("[TEST] test_positive_zero_cross_repo_imports...")
    violations = []
    # Source code extensions only — do NOT walk Markdown files
    source_exts = {".php", ".js", ".ts", ".py"}

    for root, _, files in os.walk(REPO_ROOT):
        if ".git" in root or "vendor" in root or "node_modules" in root:
            continue
        for file in files:
            fpath = Path(root) / file
            if fpath.suffix in source_exts:
                if file == "verify_architecture_boundaries.py":
                    continue
                try:
                    text = fpath.read_text(encoding="utf-8", errors="ignore")
                except (UnicodeDecodeError, OSError):
                    continue

                for pattern in CROSS_REPO_PATTERNS:
                    matches = pattern.findall(text)
                    if matches:
                        violations.append((str(fpath.relative_to(REPO_ROOT)), matches))

    assert not violations, f"Illegal cross-repo imports detected: {violations}"
    print("  -> PASS (0 cross-repo relative imports detected in source files)")


def scan_module_source_boundaries(app_modules_dir):
    """
    Real source code scanner verifying architectural boundaries:
    1. Cross-module imports must ONLY target `Contracts`.
    2. Direct internal module imports (`Domain`, `Infrastructure`, `Models`) are forbidden.
    3. Direct raw SQL access (`DB::table(...)`) to foreign tables is forbidden.
    """
    violations = []
    if not app_modules_dir.exists():
        return [{'type': 'MODULE_ROOT_MISSING', 'file': str(app_modules_dir), 'line': 0}]

    import_regex = re.compile(r"use\s+App\\Modules\\([A-Za-z0-9]+)\\([A-Za-z0-9\\]+);")
    sql_regex = re.compile(r"""(?:DB::table|from)\(\s*['"]([a-zA-Z0-9_]+)['"]\s*\)""")

    for mod_path in app_modules_dir.iterdir():
        if not mod_path.is_dir():
            continue
        mod_name = mod_path.name
        foreign_tables = set()
        for other_mod, tables in MODULE_TABLES.items():
            if other_mod != mod_name:
                foreign_tables.update(tables)

        for fpath in mod_path.glob("**/*.php"):
            if "Contracts" in fpath.parts:
                continue
            try:
                lines = fpath.read_text(encoding="utf-8").splitlines()
            except (UnicodeDecodeError, OSError):
                continue

            for idx, line in enumerate(lines, 1):
                # 1. Check cross-module use imports
                match = import_regex.search(line)
                if match:
                    target_mod, subpath = match.group(1), match.group(2)
                    if target_mod != mod_name:
                        if not subpath.startswith("Contracts"):
                            violations.append({
                                "file": str(fpath),
                                "line": idx,
                                "type": "CROSS_MODULE_INTERNAL_IMPORT",
                                "message": f"Module {mod_name} illegally imports internal '{subpath}' from {target_mod}. Only Contracts allowed.",
                            })

                # 2. Check direct cross-module SQL table access
                sql_match = sql_regex.search(line)
                if sql_match:
                    table_name = sql_match.group(1)
                    if table_name in foreign_tables:
                        violations.append({
                            "file": str(fpath),
                            "line": idx,
                            "type": "CROSS_MODULE_SQL_ACCESS",
                            "message": f"Module {mod_name} illegally queries foreign table '{table_name}'. Must use module contract service.",
                        })

    return violations


def test_positive_real_modules_boundary_check():
    """Verify that current codebase has zero cross-module boundary violations."""
    print("[TEST] test_positive_real_modules_boundary_check...")
    app_modules_dir = REPO_ROOT / "app" / "Modules"
    violations = scan_module_source_boundaries(app_modules_dir)
    assert not violations, f"Boundary violations detected in app/Modules: {violations}"
    assert any(app_modules_dir.rglob("*.php")), "No implemented production modules found"
    print("  -> PASS (Zero detected architectural boundary violations in implemented modules)")


def test_negative_executable_cross_module_fixtures():
    """
    Executable negative test: creates a synthetic module tree with real PHP source files,
    injects illegal cross-module internal imports and illegal foreign SQL queries,
    and asserts that scan_module_source_boundaries catches them with precise line numbers.
    """
    print("[TEST] test_negative_executable_cross_module_fixtures (Executable Negative)...")
    temp_dir = Path(tempfile.mkdtemp(prefix="usayd_arch_test_"))
    try:
        # Create synthetic modules: Identity and Payments
        identity_dir = temp_dir / "Identity" / "Services"
        identity_dir.mkdir(parents=True)
        payments_dir = temp_dir / "Payments" / "Contracts"
        payments_dir.mkdir(parents=True)
        payments_infra = temp_dir / "Payments" / "Infrastructure"
        payments_infra.mkdir(parents=True)

        # 1. Legal file: Identity imports Payments\Contracts
        legal_php = identity_dir / "LegalService.php"
        legal_php.write_text(
            "<?php\n\nnamespace App\\Modules\\Identity\\Services;\n\n"
            "use App\\Modules\\Payments\\Contracts\\PaymentGatewayInterface;\n\n"
            "class LegalService {}\n",
            encoding="utf-8"
        )

        # 2. Illegal file: Identity imports Payments\Infrastructure (internal)
        illegal_import_php = identity_dir / "IllegalImportService.php"
        illegal_import_php.write_text(
            "<?php\n\nnamespace App\\Modules\\Identity\\Services;\n\n"
            "use App\\Modules\\Payments\\Infrastructure\\PaymentGatewayImpl;\n\n"
            "class IllegalImportService {}\n",
            encoding="utf-8"
        )

        # 3. Illegal file: Identity executes raw SQL against Payments' 'invoices' table
        illegal_sql_php = identity_dir / "IllegalSqlService.php"
        illegal_sql_php.write_text(
            "<?php\n\nnamespace App\\Modules\\Identity\\Services;\n\n"
            "use Illuminate\\Support\\Facades\\DB;\n\n"
            "class IllegalSqlService {\n"
            "    public function getInvoices() {\n"
            "        return DB::table('invoices')->get();\n"
            "    }\n"
            "}\n",
            encoding="utf-8"
        )

        violations = scan_module_source_boundaries(temp_dir)

        # Assert that exactly 2 violations were found
        assert len(violations) == 2, f"Expected 2 violations, got {len(violations)}: {violations}"

        # Check violation types
        types = {v["type"] for v in violations}
        assert "CROSS_MODULE_INTERNAL_IMPORT" in types, "Scanner failed to detect CROSS_MODULE_INTERNAL_IMPORT"
        assert "CROSS_MODULE_SQL_ACCESS" in types, "Scanner failed to detect CROSS_MODULE_SQL_ACCESS"

        # Check that LegalService produced 0 violations
        for v in violations:
            assert "LegalService.php" not in v["file"], f"False positive reported on LegalService.php: {v}"

        print("  -> PASS (Executable negative fixture successfully verified AST/regex detection)")
    finally:
        shutil.rmtree(temp_dir, ignore_errors=True)


def run_all_tests():
    print("==================================================")
    print(" Usayd Academy — Architecture Boundary Test Suite ")
    print(" Ticket: USYD-T0022 / Architectural Rigor V2      ")
    print("==================================================")
    test_positive_adr_and_boundary_docs()
    test_positive_zero_cross_repo_imports()
    test_positive_real_modules_boundary_check()
    test_negative_executable_cross_module_fixtures()
    print("==================================================")
    print(" ALL 4 ARCHITECTURE BOUNDARY TESTS PASSED (0 FAIL)")
    print("==================================================")


if __name__ == "__main__":
    run_all_tests()
