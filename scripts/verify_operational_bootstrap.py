#!/usr/bin/env python3
"""Read-only verification of documented Artisan commands and locked runtime."""
import argparse
import json
from pathlib import Path

REQUIRED_COMMANDS = {"migrate", "migrate:status", "serve", "queue:work", "usayd:queue-dead-letter"}


def verify(lock, inventory):
    commands = {entry["name"] for entry in inventory["commands"]}
    missing = REQUIRED_COMMANDS - commands
    if missing:
        raise ValueError("Missing documented Artisan commands: " + ", ".join(sorted(missing)))
    packages = {entry["name"]: entry for entry in lock["packages"]}
    if "laravel/horizon" in packages or "horizon" in commands:
        raise ValueError("Horizon availability changed; review operational runbook")
    kernel = packages["symfony/http-kernel"]
    if not kernel.get("require", {}).get("php"):
        raise ValueError("Missing locked PHP platform requirement")
    print("Operational bootstrap verified: documented commands registered; Horizon not installed.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("lock", type=Path)
    parser.add_argument("inventory", type=Path)
    args = parser.parse_args()
    try:
        verify(json.loads(args.lock.read_text(encoding="utf-8")),
               json.loads(args.inventory.read_text(encoding="utf-8")))
    except (ValueError, KeyError, TypeError, OSError) as error:
        parser.exit(1, f"Operational verification failed: {error}\n")


if __name__ == "__main__":
    main()
