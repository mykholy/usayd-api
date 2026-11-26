# Dependency security and locked component inventory

USYD-T0036 / [USYD-79](https://mykholy.atlassian.net/browse/USYD-79), S02. Applies to API only. No production secrets, resources, schema, application route or Flutter changes.

- Dependabot weekly version-update configuration for Composer and GitHub Actions; proposals must pass CI/review. Configuration becomes active on default branch; actual first Dependabot run must be verified. No unattended merge or security advisory dismissal.
- `composer.lock` is the deployment dependency source of truth. Update only via Composer in a focused reviewed PR, commit both manifests, review version/diff/provenance, run full CI. Normal build uses `composer install` not update. Audit locked runtime and dev packages with `composer --no-plugins --no-scripts audit --locked --no-interaction`; nonzero exits block, investigate CVEs/abandoned packages and patch or document approved exception; no silent suppression. CI checks manifest files unchanged.
- Gitleaks official image pinned to version and digest scans reachable HEAD history with redacted output and no network; validates a clean synthetic fixture and detects a deliberately fake format-only token, requiring exact detection exit code. Scan must not print raw credentials. A finding means stop, assess exposure, revoke/rotate genuine credentials via authorized owner and handle history remediation separately; no automatic history rewrite. A clean scan is not proof of absence of secrets.
- Generate deterministic CycloneDX 1.5 component inventory from exact composer.lock versions, with Package URLs and runtime/development annotations; CI publishes JSON for 14 days. It inventories Composer packages only (not OS, PHP runtime, Docker layers, dependency relationship graph or a signed deployment SBOM). Verify artifact and retain per release. No real credentials in fixtures or artifact.
- New workflow has read-only contents permissions; checkout and artifact actions are commit-pinned, Gitleaks image digest-pinned. Existing workflow actions, setup-php and service images still need a separate complete pinning/provenance audit; do not claim all tooling is immutable.

## Reproduce
```sh
composer --no-plugins --no-scripts validate --strict
composer --no-plugins --no-scripts audit --locked --no-interaction
python3 -m unittest discover -s scripts/tests -p test_composer_sbom.py
python3 scripts/generate_composer_sbom.py --output /tmp/usayd-composer.cdx.json
```
See `.github/workflows/security.yml` for scanner invocation and synthetic tests. Run existing API CI as well. A failing audit, scanner, inventory, artifact step or missing review is not a success. Required GitHub branch check enforcement must be verified separately under USYD-54. Sources: [Composer CLI](https://getcomposer.org/doc/03-cli.md#audit), [Dependabot](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference), [Gitleaks](https://github.com/gitleaks/gitleaks).

## Historical findings reviewed (2026-11-26)

Three findings in the initial reachable-history scan were inspected against the exact historical files. The CI workflow at commit `70f4675` used disposable SeaweedFS fixture credentials only on loopback and the same credentials in its isolated container. The two findings in `TransactionalOutboxTest.php` at commit `ca9c740` were equal synthetic `idempotency_key` values intentionally repeated to exercise database uniqueness; neither is an authentication credential. The three exact commit/file/rule/line fingerprints are recorded in `.gitleaksignore`; no rule, path or provider-wide exception. A new finding at a new fingerprint still fails. Review the allowlist during future security PRs; do not add real credentials, even if revoked, without a separately documented security decision. No raw finding content is published. This classification is technical review, not independent human signoff.
