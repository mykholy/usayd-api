import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from verify_release_health import probe_url, verify


class ReleaseHealthTest(unittest.TestCase):
    def setUp(self):
        self.payload = {"status": "healthy", "services": {"database": "connected", "redis": "connected"}}

    def check(self, status=200, media="application/json"):
        verify(status, media, json.dumps(self.payload))

    def test_healthy(self):
        self.check(media="application/json; charset=utf-8")

    def test_degraded(self):
        self.payload["status"] = "degraded"
        with self.assertRaises(ValueError):
            self.check()

    def test_503(self):
        with self.assertRaises(ValueError):
            self.check(status=503)

    def test_redis_disconnected(self):
        self.payload["services"]["redis"] = "disconnected"
        with self.assertRaises(ValueError):
            self.check()

    def test_diagnostics_exposed(self):
        self.payload["diagnostics"] = {}
        with self.assertRaises(ValueError):
            self.check()

    def test_invalid_media(self):
        with self.assertRaises(ValueError):
            self.check(media="text/html")

    def test_invalid_json(self):
        with self.assertRaises(ValueError):
            verify(200, "application/json", "invalid")

    def test_missing_services(self):
        del self.payload["services"]
        with self.assertRaises(ValueError):
            self.check()

    def test_origin(self):
        self.assertEqual(probe_url("https://example.com"), "https://example.com/api/v1/health")
        self.assertEqual(probe_url("http://127.0.0.1:8000"), "http://127.0.0.1:8000/api/v1/health")

    def test_unsafe_origins(self):
        for value in ("http://example.com", "https://user:pass@example.com", "https://example.com/path", "https://example.com?secret=value"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                probe_url(value)


if __name__ == "__main__":
    unittest.main()
