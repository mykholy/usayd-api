import copy
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from verify_operational_bootstrap import REQUIRED_COMMANDS, verify


class OperationalBootstrapTest(unittest.TestCase):
    def setUp(self):
        self.lock = {"packages": [{"name": "symfony/http-kernel", "require": {"php": ">=8.4.1"}}]}
        self.inventory = {"commands": [{"name": name} for name in sorted(REQUIRED_COMMANDS)]}

    def test_expected_inventory(self):
        verify(self.lock, self.inventory)

    def test_missing_command_rejected(self):
        self.inventory["commands"] = [x for x in self.inventory["commands"] if x["name"] != "queue:work"]
        with self.assertRaisesRegex(ValueError, "Missing documented"):
            verify(self.lock, self.inventory)

    def test_horizon_change_requires_runbook_review(self):
        lock = copy.deepcopy(self.lock)
        lock["packages"].append({"name": "laravel/horizon"})
        with self.assertRaisesRegex(ValueError, "Horizon availability"):
            verify(lock, self.inventory)
        self.inventory["commands"].append({"name": "horizon"})
        with self.assertRaisesRegex(ValueError, "Horizon availability"):
            verify(self.lock, self.inventory)

    def test_missing_platform_metadata_rejected(self):
        self.lock["packages"][0]["require"] = {}
        with self.assertRaisesRegex(ValueError, "platform"):
            verify(self.lock, self.inventory)


if __name__ == "__main__":
    unittest.main()
