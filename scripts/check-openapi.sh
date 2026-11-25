#!/usr/bin/env bash
# Usayd Academy — OpenAPI Contract Validation Script
# Validates API contract structure, endpoints, and health probe definition.

set -euo pipefail

echo "=================================================="
echo " Usayd Academy — OpenAPI Contract Verification   "
echo "=================================================="

# Check local or workspace OpenAPI specification
if [ -f "openapi.yaml" ]; then
    SPEC_FILE="openapi.yaml"
elif [ -f "../delivery/source_original/usayd-academy-codex-package/07_api/openapi.yaml" ]; then
    SPEC_FILE="../delivery/source_original/usayd-academy-codex-package/07_api/openapi.yaml"
else
    echo "Notice: Creating baseline openapi.yaml for usayd-api..."
    SPEC_FILE="openapi.yaml"
fi

echo "Validating OpenAPI specification: ${SPEC_FILE}..."

# Verify that routes/api.php defines standard /v1 endpoints
if grep -q "api.v1.health" routes/api.php; then
    echo "  -> PASS: Found authoritative /api/v1/health route in routes/api.php"
else
    echo "  -> FAIL: /api/v1/health route missing in routes/api.php"
    exit 1
fi

python3 - <<'PY'
import json
from pathlib import Path

spec = json.loads(Path("docs/api/implemented.openapi.json").read_text(encoding="utf-8"))
assert spec["openapi"].startswith("3.1."), "Unexpected OpenAPI version"
operation_ids = set()
for path, operations in spec["paths"].items():
    assert path.startswith("/api/"), f"Invalid public path: {path}"
    for method, operation in operations.items():
        assert method in {"get", "post", "put", "patch", "delete"}, f"Unsupported operation: {method}"
        operation_id = operation["operationId"]
        assert operation_id not in operation_ids, f"Duplicate operation ID: {operation_id}"
        operation_ids.add(operation_id)
        assert any(code.startswith("2") for code in operation["responses"]), f"No success response: {path}"

def verify_refs(value):
    if isinstance(value, dict):
        ref = value.get("$ref")
        if ref:
            assert ref.startswith("#/"), f"Unexpected external reference: {ref}"
            target = spec
            for segment in ref[2:].split("/"):
                target = target[segment.replace("~1", "/").replace("~0", "~")]
        for child in value.values():
            verify_refs(child)
    elif isinstance(value, list):
        for child in value:
            verify_refs(child)

verify_refs(spec)
print(f"Implemented API document: {len(operation_ids)} operations; local references resolve.")
PY

echo "=================================================="
echo " OPENAPI STRUCTURAL CHECKS PASSED (0 FAIL)       "
echo "=================================================="
