#!/usr/bin/env bash
# Lint the implemented contract against production-registered routes.
# This is a read-only route inventory; never run migrations in this script.
set -euo pipefail

cd "$(dirname "$0")/.."
routes="$(mktemp)"
trap 'rm -f "$routes"' EXIT

python3 -m unittest discover -s scripts/tests -p test_openapi_contract.py
APP_ENV=production APP_DEBUG=false php artisan route:list --json --path=api > "$routes"
python3 scripts/verify_openapi_contract.py docs/api/implemented.openapi.json "$routes"
