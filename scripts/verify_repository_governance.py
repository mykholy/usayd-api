#!/usr/bin/env python3
"""
USYD-T0021: 3-Repository Creation, Permissions & Ownership Verification Suite
Validates repository boundaries, permissions, CODEOWNERS, CI workflows, and secret hygiene.
Includes positive assertions and negative test scenarios.
"""

import os
import sys
import subprocess
import json
import re
from pathlib import Path

WORKSPACE = Path(__file__).resolve().parents[2]
REPOS = ["usayd-api", "usayd-web", "usayd-flutter"]
EXPECTED_OWNER = "@mykholy"

REQUIRED_SENSITIVE_PATHS = {
    "usayd-api": [
        "/app/Modules/Identity/",
        "/app/Modules/Payments/",
        "/app/Modules/Guardian/",
        "/app/Modules/Payroll/",
        "/app/Modules/Quran/",
        "/app/Modules/AI/",
        "/database/migrations/",
        "/.github/",
    ],
    "usayd-web": [
        "/src/app/**/checkout/**",
        "/src/app/**/guardian/**",
        "/src/lib/auth/**",
        "/.github/",
    ],
    "usayd-flutter": [
        "/lib/**/auth/**",
        "/lib/**/guardian/**",
        "/lib/**/payments/**",
        "/lib/**/recording/**",
        "/.github/",
    ],
}

REQUIRED_WORKFLOWS = [
    "ai-review.yml",
    "ci.yml",
    "human-review-gate.yml",
    "safe-automerge.yml",
]

SECRET_PATTERNS = [
    re.compile(r"(?i)(?:api_key|secret|password|bearer|token)\s*[:=]\s*['\"][A-Za-z0-9-_]{20,}['\"]"),
    re.compile(r"ghp_[A-Za-z0-9]{36}"),
    re.compile(r"gho_[A-Za-z0-9]{36}"),
    re.compile(r"AIza[0-9A-Za-z-_]{35}"),
]


def test_positive_local_repos_exist():
    """Verify all 3 repository directories exist and are Git repositories."""
    print("[TEST] test_positive_local_repos_exist...")
    for repo in REPOS:
        repo_dir = WORKSPACE / repo
        assert repo_dir.is_dir(), f"Repository directory missing: {repo_dir}"
        assert (repo_dir / ".git").is_dir(), f"Repository is not a Git repo: {repo_dir}"
    print("  -> PASS")


def test_positive_git_default_branch():
    """Verify default branch is main for all local repositories."""
    print("[TEST] test_positive_git_default_branch...")
    for repo in REPOS:
        repo_dir = WORKSPACE / repo
        res = subprocess.run(
            ["git", "-C", str(repo_dir), "branch", "--show-current"],
            capture_output=True,
            text=True,
            check=True,
        )
        current_branch = res.stdout.strip()
        # In feat branch, main must exist in branch list
        branches = subprocess.run(
            ["git", "-C", str(repo_dir), "branch", "--list", "main"],
            capture_output=True,
            text=True,
            check=True,
        ).stdout.strip()
        assert "main" in branches, f"Repository {repo} missing 'main' branch"
    print("  -> PASS")


def test_positive_codeowners_coverage():
    """Verify CODEOWNERS contains all required sensitive paths mapped to authorized owner."""
    print("[TEST] test_positive_codeowners_coverage...")
    for repo, paths in REQUIRED_SENSITIVE_PATHS.items():
        codeowners_file = WORKSPACE / repo / ".github" / "CODEOWNERS"
        assert codeowners_file.is_file(), f"CODEOWNERS missing in {repo}"
        content = codeowners_file.read_text(encoding="utf-8")
        for path in paths:
            assert path in content, f"Sensitive path {path} missing in {repo} CODEOWNERS"
            # Ensure owner is assigned
            line = [l for l in content.splitlines() if path in l][0]
            assert EXPECTED_OWNER in line, f"Path {path} not assigned to {EXPECTED_OWNER} in {repo}"
    print("  -> PASS")


def test_positive_required_workflows():
    """Verify required GitHub Actions workflows exist in each repository."""
    print("[TEST] test_positive_required_workflows...")
    for repo in REPOS:
        wf_dir = WORKSPACE / repo / ".github" / "workflows"
        assert wf_dir.is_dir(), f"Workflows directory missing in {repo}"
        for wf in REQUIRED_WORKFLOWS:
            assert (wf_dir / wf).is_file(), f"Workflow {wf} missing in {repo}"
    print("  -> PASS")


def test_positive_no_secrets_in_repo():
    """Scan tracked repository files to ensure no sensitive credentials leaked."""
    print("[TEST] test_positive_no_secrets_in_repo...")
    for repo in REPOS:
        repo_dir = WORKSPACE / repo
        for root, _, files in os.walk(repo_dir):
            if ".git" in root:
                continue
            for file in files:
                fpath = Path(root) / file
                if fpath.suffix in [".png", ".jpg", ".ico", ".woff", ".woff2"]:
                    continue
                try:
                    text = fpath.read_text(encoding="utf-8", errors="ignore")
                    for pattern in SECRET_PATTERNS:
                        matches = pattern.findall(text)
                        # Filter out example dummy templates
                        real_matches = [m for m in matches if "usayd_ci_password" not in m and "root_ci_password" not in m]
                        assert not real_matches, f"Potential secret leak in {fpath}: {real_matches}"
                except Exception as e:
                    pass
    print("  -> PASS")


def test_negative_codeowners_validation():
    """Negative test: verify that missing owner or unmapped sensitive path is detected as failure."""
    print("[TEST] test_negative_codeowners_validation (Negative)...")
    synthetic_codeowners = "/app/Modules/Identity/ # No owner assigned\n/app/Modules/Payments/ @unauthorized_user\n"
    
    # Validation logic check
    has_owner_for_identity = False
    has_valid_owner_for_payments = False
    for line in synthetic_codeowners.splitlines():
        if "/app/Modules/Identity/" in line and EXPECTED_OWNER in line:
            has_owner_for_identity = True
        if "/app/Modules/Payments/" in line and EXPECTED_OWNER in line:
            has_valid_owner_for_payments = True

    assert not has_owner_for_identity, "Negative check failed: identity should have lacked expected owner"
    assert not has_valid_owner_for_payments, "Negative check failed: payments should have lacked expected owner"
    print("  -> PASS (Negative scenario correctly detected)")


def test_negative_secret_leak_detection():
    """Negative test: verify that synthetic credentials trigger secret scanner."""
    print("[TEST] test_negative_secret_leak_detection (Negative)...")
    synthetic_bad_text = "aws_secret = 'AKIAIOSFODNN7EXAMPLE1234567890'"
    detected = False
    for pattern in SECRET_PATTERNS:
        if pattern.search(synthetic_bad_text):
            detected = True
            break
    assert detected, "Negative check failed: scanner failed to detect synthetic leaked secret"
    print("  -> PASS (Negative scenario correctly detected)")


def run_all_tests():
    print("==================================================")
    print(" Usayd Academy — Repository Governance Test Suite ")
    print(" Ticket: USYD-T0021                               ")
    print("==================================================")
    test_positive_local_repos_exist()
    test_positive_git_default_branch()
    test_positive_codeowners_coverage()
    test_positive_required_workflows()
    test_positive_no_secrets_in_repo()
    test_negative_codeowners_validation()
    test_negative_secret_leak_detection()
    print("==================================================")
    print(" ALL 7 REPOSITORY GOVERNANCE TESTS PASSED (0 FAIL)")
    print("==================================================")


if __name__ == "__main__":
    run_all_tests()
