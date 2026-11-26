# Published API contract and consumer compatibility

USYD-T0037 / [USYD-80](https://mykholy.atlassian.net/browse/USYD-80), S02. API is the producer; web and Flutter are consumers. The source specification in delivery/07_api is actually located under source_original/usayd-academy-codex-package/07_api; its 121 baseline operations are planned requirements and 57 proposed operations need separate approval. Neither is a deployed SDK contract.

## Authoritative implemented artifact

Use only `docs/api/implemented.openapi.json` (currently version 0.1.0) for implemented-route SDK generation. API CI first verifies production route parity and local references, then compares the exact event base commit's implemented contract. It packages the exact JSON bytes, `manifest.json` containing stable version, source commit SHA and SHA256, and `SHA256SUMS`. CI verifies the digest before upload; the artifact name includes the tested commit SHA and is retained for 30 days. Pull-request artifacts are candidates (including merge-test commit SHA), not production releases. A successful main CI artifact is build evidence only; promote to a durable release asset after required release approval. No deployment or production data involved.

## Version and compatibility policy

- Stable MAJOR.MINOR.PATCH; no prerelease/leading-zero identifiers. The contract artifact version is independent of application version and the /api/v1 route prefix.
- No change to published bytes under the same version. Documentation-only edits to info.title/description or formatting require a patch increment.
- A new operation/path or component requires a minor increment, preserving every existing operation, component, path-level metadata and global contract/security settings. Route parity still applies to additions; proposed endpoints remain subject to Gate A.
- The conservative v1 freeze rejects any structural change to an existing operation or existing component, even if a human might judge a particular change backward compatible. No automatic exception or major-version bypass. A future approved parallel v2 contract and migration plan must have separate reviewed tooling.
- Treat this as an intentionally strict structural regression gate, not a complete semantic OpenAPI diff or substitute for behavior, authorization and consumer integration tests. N/N-1/N-2 mobile compatibility is a release acceptance requirement, not verified by this producer-only gate. No unsupported client cutoff or store link is activated.

## Consumer SDK generation policy

1. Select a successful approved main/release artifact for a specific tested SHA. Verify the manifest SHA, version and `sha256sum --check SHA256SUMS`. Pin the producer commit, contract version/hash, generator version and templates in consumer repository metadata.
2. Generate TypeScript/Dart clients in separate consumer PRs after technical approval of the tool and settings. Never generate SDKs from the draft or proposed specification. Preserve stable machine error codes, AR/EN localized human text, independent locale/timezone/country, bearer and first-party CSRF behavior; tolerate unknown response fields where the schema permits. No generated clients committed by this producer PR.
3. Run consumer build, lint, contract and positive/negative authorization tests against the selected API release in an isolated environment. Test token refresh, error mapping, feature gating and supported client versions. Record evidence and link consumer tickets; mobile developer owns Flutter implementation.
4. Keep previous compatible artifacts/clients for rollback. Do not switch live traffic until the coordinated release checklist and all approvals are met.

## Reproduce locally (read only)

```sh
git show main:docs/api/implemented.openapi.json > /tmp/usayd-contract-base.json
python3 -m unittest discover -s scripts/tests -p test_contract_publication.py
bash scripts/check-openapi.sh
python3 scripts/publish_api_contract.py --baseline /tmp/usayd-contract-base.json --current docs/api/implemented.openapi.json --commit "$(git rev-parse HEAD)" --output /tmp/usayd-contract
(cd /tmp/usayd-contract && sha256sum --check SHA256SUMS)
```

Use the exact merge-base/release SHA appropriate to the change, not an unverified stale branch. Actual CI uses the GitHub event base SHA and fails closed if missing. No changes to Flutter, web, deployed contracts or original delivery source files by this task.
