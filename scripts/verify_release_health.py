#!/usr/bin/env python3
"""Read-only strict deployment readiness probe. Never deploys or migrates."""
import argparse
import json
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def verify(status, content_type, payload):
    if status != 200:
        raise ValueError("readiness HTTP status is not 200")
    if content_type.split(";")[0].strip().lower() != "application/json":
        raise ValueError("unexpected readiness media type")
    data = json.loads(payload)
    if not isinstance(data, dict) or data.get("status") != "healthy":
        raise ValueError("application is not healthy")
    services = data.get("services")
    if not isinstance(services, dict) or any(services.get(key) != "connected" for key in ("database", "redis")):
        raise ValueError("required dependency is not connected")
    if any(key in data for key in ("debug", "diagnostics", "runtime")):
        raise ValueError("production diagnostics exposed")


def probe_url(base):
    parts = urlsplit(base)
    if (not parts.hostname or parts.username or parts.password or parts.query or parts.fragment
            or parts.path not in ("", "/")):
        raise ValueError("use an origin without credentials, path or query")
    if parts.scheme != "https" and not (parts.scheme == "http" and parts.hostname in ("localhost", "127.0.0.1", "::1")):
        raise ValueError("HTTPS required outside loopback")
    return base.rstrip("/") + "/api/v1/health"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("origin")
    parser.add_argument("--timeout", type=float, default=10)
    args = parser.parse_args()
    try:
        if not 0 < args.timeout <= 60:
            raise ValueError("timeout must be between 0 and 60 seconds")
        request = Request(probe_url(args.origin), headers={"Accept": "application/json", "Accept-Language": "en"})
        with build_opener(NoRedirect()).open(request, timeout=args.timeout) as response:
            body = response.read(65537)
            if len(body) > 65536:
                raise ValueError("readiness response exceeds limit")
            verify(response.status, response.headers.get("Content-Type", ""), body)
    except (ValueError, HTTPError, URLError, OSError):
        print("Release readiness FAILED; inspect server-side health and deployment logs.", file=sys.stderr)
        return 1
    print("Release readiness PASSED: healthy database and Redis.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
