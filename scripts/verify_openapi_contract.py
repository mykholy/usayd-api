#!/usr/bin/env python3
"""Lint the implemented contract against production-registered API routes."""

import argparse
import json
import re
from pathlib import Path

HTTP_METHODS = {"get", "post", "put", "patch", "delete", "head", "options", "trace"}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def resolve(document, reference):
    require(isinstance(reference, str) and reference.startswith("#/"),
            f"Only local references are supported: {reference}")
    target = document
    try:
        for segment in reference[2:].split("/"):
            target = target[segment.replace("~1", "/").replace("~0", "~")]
    except (KeyError, TypeError) as error:
        raise ValueError(f"Unresolved reference: {reference}") from error
    return target


def check_references(document, node, named_map=False):
    if isinstance(node, dict):
        if not named_map and "$ref" in node:
            resolve(document, node["$ref"])
        for key, child in node.items():
            if not named_map:
                if key in {"example", "default", "enum", "const", "value"}:
                    continue
                if key == "examples" and isinstance(child, list):
                    continue
                if key.startswith("x-"):
                    continue
            # Names in maps are identifiers, not object keywords (e.g. a
            # schema property called "default" still contains a schema).
            check_references(document, child, not named_map and key in {
                "properties", "patternProperties", "$defs", "dependentSchemas",
                "schemas", "responses", "parameters", "examples", "headers",
                "requestBodies", "securitySchemes", "links", "callbacks",
                "pathItems", "paths", "content",
            } and isinstance(child, dict))
    elif isinstance(node, list):
        for child in node:
            check_references(document, child)


def path_item(document, item, seen=None):
    if "$ref" not in item:
        return item
    seen = set() if seen is None else seen
    reference = item["$ref"]
    require(reference not in seen, f"Cyclic path item reference: {reference}")
    target = path_item(document, resolve(document, reference), seen | {reference})
    siblings = {key: value for key, value in item.items() if key != "$ref"}
    require(not (target.keys() & siblings.keys()),
            f"Ambiguous referenced path item fields: {reference}")
    return target | siblings


def parameters(document, entries):
    result = {}
    for entry in entries:
        if "$ref" in entry:
            entry = resolve(document, entry["$ref"])
        key = (entry["in"], entry["name"])
        require(key not in result, f"Duplicate parameter: {key}")
        result[key] = entry
    return result


def lint(document, routes):
    require(document["openapi"].startswith("3.1."), "Expected OpenAPI 3.1")
    require(document["info"].get("title") and document["info"].get("version"),
            "Missing contract title or version")
    check_references(document, document)
    operations = set()
    operation_ids = set()
    schemes = document.get("components", {}).get("securitySchemes", {})
    for path, item in document["paths"].items():
        require(path.startswith("/api/"), f"Invalid API path: {path}")
        item = path_item(document, item)
        common = parameters(document, item.get("parameters", []))
        for method, operation in item.items():
            if method in {"summary", "description", "parameters", "servers"} or method.startswith("x-"):
                continue
            require(method in HTTP_METHODS, f"Unsupported path item: {method} {path}")
            operation_id = operation["operationId"]
            require(isinstance(operation_id, str) and operation_id.strip(),
                    f"Missing operation ID: {method} {path}")
            require(operation_id not in operation_ids, f"Duplicate operation ID: {operation_id}")
            operation_ids.add(operation_id)
            responses = operation["responses"]
            require(any(re.fullmatch(r"2(?:[0-9]{2}|XX)", code) for code in responses),
                    f"Missing success response: {operation_id}")
            for code, response in responses.items():
                require(code == "default" or re.fullmatch(r"[1-5](?:[0-9]{2}|XX)", code),
                        f"Invalid response status: {code}")
                if "$ref" in response:
                    response = resolve(document, response["$ref"])
                require(isinstance(response.get("description"), str),
                        f"Missing response description: {operation_id} {code}")
            declared = common | parameters(document, operation.get("parameters", []))
            expected = set(re.findall(r"\{([^}]+)\}", path))
            actual = {name for location, name in declared if location == "path"}
            require(expected == actual, f"Path parameter mismatch: {method} {path}")
            for name in expected:
                require(declared[("path", name)].get("required") is True,
                        f"Path parameter must be required: {name}")
            for requirement in operation.get("security", document.get("security", [])):
                require(set(requirement) <= set(schemes), f"Unknown security scheme: {operation_id}")
            operations.add((method.upper(), path))

    registered = set()
    for route in routes:
        path = "/" + route["uri"].lstrip("/")
        if not path.startswith("/api/"):
            continue
        require(not path.startswith("/api/v1/test/"),
                f"Test harness exposed in production route inventory: {path}")
        methods = set(route["method"].split("|"))
        if "GET" in methods:
            methods.discard("HEAD")  # Laravel implicitly adds HEAD to GET.
        for method in methods:
            key = (method, path)
            require(key not in registered, f"Duplicate registered route: {key}")
            registered.add(key)

    require(operations == registered,
            f"Contract/route mismatch: undocumented={sorted(registered - operations)}; "
            f"unregistered={sorted(operations - registered)}")
    return len(operations)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("contract", type=Path)
    parser.add_argument("routes", type=Path)
    args = parser.parse_args()
    try:
        count = lint(json.loads(args.contract.read_text(encoding="utf-8")),
                     json.loads(args.routes.read_text(encoding="utf-8")))
    except (OSError, ValueError, KeyError, TypeError, AttributeError) as error:
        parser.exit(1, f"OpenAPI lint failed: {error}\n")
    print(f"OpenAPI lint passed: {count} operations match production API routes.")


if __name__ == "__main__":
    main()
