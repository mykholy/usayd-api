import copy
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from verify_openapi_contract import lint


class OpenApiContractTest(unittest.TestCase):
    def setUp(self):
        self.contract = {
            "openapi": "3.1.0",
            "info": {"title": "Test", "version": "1"},
            "paths": {
                "/api/v1/items/{id}": {
                    "get": {
                        "operationId": "item",
                        "parameters": [{"in": "path", "name": "id", "required": True,
                                        "schema": {"type": "integer"}}],
                        "security": [],
                        "responses": {"200": {"description": "Success"}},
                    }
                }
            }
        }
        self.routes = [{"method": "GET|HEAD", "uri": "api/v1/items/{id}"}]
        self.operation = self.contract["paths"]["/api/v1/items/{id}"]["get"]

    def test_implicit_head_does_not_require_duplicate_contract(self):
        self.assertEqual(lint(self.contract, self.routes), 1)

    def test_undocumented_route_fails(self):
        self.routes.append({"method": "POST", "uri": "api/v1/items"})
        with self.assertRaisesRegex(ValueError, "undocumented"):
            lint(self.contract, self.routes)

    def test_unregistered_operation_fails(self):
        with self.assertRaisesRegex(ValueError, "unregistered"):
            lint(self.contract, [])

    def test_test_harness_cannot_be_exposed(self):
        self.routes.append({"method": "GET", "uri": "api/v1/test/errors"})
        with self.assertRaisesRegex(ValueError, "Test harness exposed"):
            lint(self.contract, self.routes)

    def test_duplicate_operation_id_fails(self):
        self.contract["paths"]["/api/v1/items/{id}"]["post"] = copy.deepcopy(self.operation)
        with self.assertRaisesRegex(ValueError, "Duplicate operation ID"):
            lint(self.contract, self.routes)

    def test_optional_path_parameter_fails(self):
        self.operation["parameters"][0]["required"] = False
        with self.assertRaisesRegex(ValueError, "must be required"):
            lint(self.contract, self.routes)

    def test_unresolved_reference_fails(self):
        self.operation["responses"]["200"] = {"$ref": "#/components/responses/Missing"}
        with self.assertRaisesRegex(ValueError, "Unresolved reference"):
            lint(self.contract, self.routes)

    def test_unknown_security_scheme_fails(self):
        self.operation["security"] = [{"Missing": []}]
        with self.assertRaisesRegex(ValueError, "Unknown security scheme"):
            lint(self.contract, self.routes)

    def test_missing_success_response_fails(self):
        self.operation["responses"] = {"400": {"description": "Error"}}
        with self.assertRaisesRegex(ValueError, "Missing success response"):
            lint(self.contract, self.routes)


if __name__ == "__main__":
    unittest.main()
