#!/usr/bin/env python3
"""Generate a deterministic CycloneDX component inventory from composer.lock."""
import argparse
import json
from pathlib import Path
from urllib.parse import quote


def build_bom(lock):
    components = []
    seen = set()
    for section, scope in (("packages", "runtime"), ("packages-dev", "development")):
        packages = lock.get(section)
        if not isinstance(packages, list):
            raise ValueError("missing or invalid lock section: " + section)
        for package in packages:
            name, version = package.get("name"), package.get("version")
            if not isinstance(name, str) or name.count("/") != 1 or not isinstance(version, str) or not version:
                raise ValueError("invalid locked package identity")
            group, short = name.split("/")
            if not group or not short or name in seen:
                raise ValueError("duplicate or invalid locked package")
            seen.add(name)
            purl = f"pkg:composer/{quote(group, safe='')}/{quote(short, safe='')}@{quote(version, safe='')}"
            components.append({
                "type": "library",
                "group": group,
                "name": short,
                "version": version,
                "bom-ref": purl,
                "purl": purl,
                "properties": [{"name": "usayd:dependency-scope", "value": scope}],
            })
    if not components:
        raise ValueError("empty locked dependency inventory")
    return {
        "bomFormat": "CycloneDX",
        "specVersion": "1.5",
        "version": 1,
        "components": sorted(components, key=lambda c: c["bom-ref"]),
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--lock", default="composer.lock", type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    bom = build_bom(json.loads(args.lock.read_text(encoding="utf-8")))
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(bom, indent=2) + "\n", encoding="utf-8")
    print(f"Locked Composer component inventory: {len(bom['components'])} components")


if __name__ == "__main__":
    main()
