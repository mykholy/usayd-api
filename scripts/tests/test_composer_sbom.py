import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from generate_composer_sbom import build_bom


class ComposerSbomTest(unittest.TestCase):
    def test_exact_versions_and_scope(self):
        lock = {"packages": [{"name": "vendor/library", "version": "1.2.3"}],
                "packages-dev": [{"name": "vendor/test", "version": "2.0.0"}]}
        bom = build_bom(lock)
        self.assertEqual(bom["bomFormat"], "CycloneDX")
        self.assertEqual(len(bom["components"]), 2)
        self.assertEqual(bom["components"][0]["purl"], "pkg:composer/vendor/library@1.2.3")
        self.assertEqual(bom["components"][1]["properties"][0]["value"], "development")

    def test_deterministic_order(self):
        a = {"name": "vendor/a", "version": "1.0"}
        b = {"name": "vendor/b", "version": "1.1"}
        self.assertEqual(build_bom({"packages": [a, b], "packages-dev": []}),
                         build_bom({"packages": [b, a], "packages-dev": []}))

    def test_duplicate_rejected(self):
        a = {"name": "vendor/a", "version": "1.0"}
        with self.assertRaises(ValueError):
            build_bom({"packages": [a], "packages-dev": [a]})

    def test_invalid_and_empty_rejected(self):
        for value in ({}, {"packages": [], "packages-dev": []},
                      {"packages": [{"name": "bad", "version": "1"}], "packages-dev": []},
                      {"packages": [{"name": "vendor/a"}], "packages-dev": []}):
            with self.subTest(value=value), self.assertRaises(ValueError):
                build_bom(value)


if __name__ == "__main__":
    unittest.main()
