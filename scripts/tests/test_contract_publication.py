import copy
import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from publish_api_contract import compatible, publish


class ContractCompatibilityTest(unittest.TestCase):
    def setUp(self):
        self.old = {
            "openapi": "3.1.0", "info": {"title": "Fixture", "version": "0.1.0"},
            "paths": {"/api/v1/items": {"get": {
                "operationId": "listItems", "responses": {"200": {"description": "OK"}},
                "security": [{"bearer": []}],
            }}},
            "components": {
                "securitySchemes": {"bearer": {"type": "http", "scheme": "bearer"}},
                "schemas": {"Item": {"type": "object", "properties": {"id": {"type": "integer"}}}},
            },
        }
        self.new = copy.deepcopy(self.old)

    def test_unchanged_allowed(self):
        compatible(self.old, self.new)

    def test_new_endpoint_requires_minor_increase(self):
        self.new["paths"]["/api/v1/new"] = {"get": {"operationId": "new", "responses": {"200": {"description": "OK"}}}}
        self.new["info"]["version"] = "0.1.1"
        with self.assertRaisesRegex(ValueError, "minor"):
            compatible(self.old, self.new)
        self.new["info"]["version"] = "0.2.0"
        compatible(self.old, self.new)

    def test_new_method_and_component_allowed(self):
        self.new["paths"]["/api/v1/items"]["post"] = {"operationId": "create", "responses": {"201": {"description": "Created"}}}
        self.new["components"]["schemas"]["NewItem"] = {"type": "string"}
        self.new["info"]["version"] = "0.2.0"
        compatible(self.old, self.new)

    def test_removed_operation_rejected_even_with_version_bump(self):
        del self.new["paths"]["/api/v1/items"]["get"]
        self.new["info"]["version"] = "0.2.0"
        with self.assertRaisesRegex(ValueError, "changed existing path"):
            compatible(self.old, self.new)

    def test_existing_schema_change_rejected(self):
        self.new["components"]["schemas"]["Item"]["properties"]["id"]["type"] = "string"
        with self.assertRaisesRegex(ValueError, "changed existing component"):
            compatible(self.old, self.new)

    def test_security_relaxation_rejected(self):
        self.new["paths"]["/api/v1/items"]["get"]["security"] = []
        with self.assertRaisesRegex(ValueError, "changed existing path"):
            compatible(self.old, self.new)

    def test_global_security_and_path_metadata_frozen(self):
        self.new["security"] = []
        with self.assertRaisesRegex(ValueError, "global contract"):
            compatible(self.old, self.new)
        self.new = copy.deepcopy(self.old)
        self.new["paths"]["/api/v1/items"]["parameters"] = []
        with self.assertRaisesRegex(ValueError, "path-level"):
            compatible(self.old, self.new)

    def test_version_regression_or_major_rejected(self):
        for value in ("0.0.9", "1.0.0", "0.1.0-beta"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                self.new["info"]["version"] = value
                compatible(self.old, self.new)

    def test_same_version_different_bytes_rejected(self):
        with self.assertRaisesRegex(ValueError, "increased version"):
            compatible(self.old, self.new, bytes_changed=True)
        self.new["info"]["version"] = "0.1.1"
        compatible(self.old, self.new, bytes_changed=True)

    def test_broken_reference_rejected(self):
        self.new["info"]["version"] = "0.2.0"
        self.new["components"]["schemas"]["Broken"] = {"$ref": "#/components/schemas/Missing"}
        with self.assertRaisesRegex(ValueError, "Unresolved reference"):
            compatible(self.old, self.new)

    def test_artifact_exact_bytes_hash_version_and_commit(self):
        raw = json.dumps(self.old).encode()
        with tempfile.TemporaryDirectory() as tmp:
            output = Path(tmp)
            publish(raw, "a" * 40, output)
            manifest = json.loads((output / "manifest.json").read_text())
            self.assertEqual(manifest["version"], "0.1.0")
            self.assertEqual(manifest["source_commit"], "a" * 40)
            self.assertEqual(manifest["sha256"], hashlib.sha256(raw).hexdigest())
            self.assertEqual((output / manifest["file"]).read_bytes(), raw)
            self.assertEqual((output / "SHA256SUMS").read_text(), manifest["sha256"] + "  implemented.openapi.json\n")
            with self.assertRaises(ValueError):
                publish(raw, "main", output)


if __name__ == "__main__":
    unittest.main()
