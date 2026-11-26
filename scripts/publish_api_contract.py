#!/usr/bin/env python3
"""Conservative v1 contract freeze and immutable, versioned artifact packaging."""
import argparse
import hashlib
import json
import re
from pathlib import Path

from verify_openapi_contract import HTTP_METHODS, check_references, path_item


def version(document):
    value = document["info"]["version"]
    if not isinstance(value, str) or not re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", value):
        raise ValueError("expected stable MAJOR.MINOR.PATCH contract version")
    return tuple(map(int, value.split(".")))


def compatible(previous, current, *, bytes_changed=False):
    old, new = version(previous), version(current)
    if old[0] != new[0] or new < old:
        raise ValueError("v1 contract major change or version regression; separate approved version required")
    # Freeze global semantics, path-level metadata, existing operations and all
    # existing component entries. This is deliberately stricter than general
    # OpenAPI compatibility, even for 0.x versions.
    if {k: v for k, v in previous.items() if k not in {"info", "paths", "components"}} != {
        k: v for k, v in current.items() if k not in {"info", "paths", "components"}
    }:
        raise ValueError("global contract change requires separate reviewed compatibility policy")
    if {k: v for k, v in previous["info"].items() if k not in {"version", "title", "description"}} != {
        k: v for k, v in current["info"].items() if k not in {"version", "title", "description"}
    }:
        raise ValueError("contract info metadata changed outside approved fields")
    added = False
    old_paths, new_paths = previous["paths"], current["paths"]
    for path, raw in old_paths.items():
        if path not in new_paths:
            raise ValueError("removed path: " + path)
        before, after = path_item(previous, raw), path_item(current, new_paths[path])
        for key, value in before.items():
            if key not in after or after[key] != value:
                raise ValueError("changed existing path item: " + path + " " + key)
        for key in after.keys() - before.keys():
            if key not in HTTP_METHODS:
                raise ValueError("changed path-level metadata: " + path)
            added = True
    added |= bool(new_paths.keys() - old_paths.keys())
    old_components, new_components = previous.get("components", {}), current.get("components", {})
    for category, entries in old_components.items():
        if category not in new_components or not isinstance(entries, dict):
            raise ValueError("removed or unsupported component category: " + category)
        for key, value in entries.items():
            if new_components[category].get(key) != value:
                raise ValueError("changed existing component: " + category + "/" + key)
        added |= bool(new_components[category].keys() - entries.keys())
    added |= bool(new_components.keys() - old_components.keys())
    if added and new[1] <= old[1]:
        raise ValueError("additive contract changes require a minor version increase")
    if (previous != current or bytes_changed) and new <= old:
        raise ValueError("changed artifact requires an increased version")
    check_references(current, current)


def publish(raw, commit, output):
    document = json.loads(raw)
    version(document)
    check_references(document, document)
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise ValueError("expected exact 40-character Git SHA")
    output.mkdir(parents=True, exist_ok=True)
    filename = "implemented.openapi.json"
    digest = hashlib.sha256(raw).hexdigest()
    (output / filename).write_bytes(raw)
    manifest = {
        "contract": "usayd-api-implemented",
        "version": document["info"]["version"],
        "source_commit": commit,
        "sha256": digest,
        "file": filename,
        "scope": "implemented route contract; not product release approval",
    }
    (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    (output / "SHA256SUMS").write_text(f"{digest}  {filename}\n", encoding="utf-8")
    print(f"Contract {manifest['version']} SHA256 {digest}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--baseline", required=True, type=Path)
    parser.add_argument("--current", required=True, type=Path)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        old, new = args.baseline.read_bytes(), args.current.read_bytes()
        compatible(json.loads(old), json.loads(new), bytes_changed=old != new)
        publish(new, args.commit, args.output)
    except (OSError, ValueError, KeyError, TypeError, AttributeError) as error:
        parser.exit(1, f"Contract compatibility failed: {error}\n")


if __name__ == "__main__":
    main()
