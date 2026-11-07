# MIT, Copyright (c) 2026 Autonomic Engineering.
import hashlib
from pathlib import Path
import re
import tomllib
import unittest


class LeakPolicyTests(unittest.TestCase):
    def test_path_and_content_exceptions_are_rule_scoped(self):
        config = tomllib.loads((Path(__file__).resolve().parents[1] / ".gitleaks.toml").read_text())
        for allow in config.get("allowlists", []):
            if allow.get("paths") and allow.get("regexes"):
                self.assertTrue(allow.get("targetRules"),
                                "Global path exceptions skip directory files before content is scanned")

    def test_new_hex_token_is_not_an_inventory_exception(self):
        config = tomllib.loads((Path(__file__).resolve().parents[1] / ".gitleaks.toml").read_text())
        token = hashlib.sha256(b"synthetic unreviewed credential").hexdigest()
        finding = 'token": "' + token + '"'
        for path in ["scripts/conversion/source.json", "tests/fixtures/native-identity-files.json"]:
            for allow in config.get("allowlists", []):
                if not any(re.search(pattern, path) for pattern in allow.get("paths", [])):
                    continue
                value = token if allow.get("regexTarget", "secret") == "secret" else finding
                self.assertFalse(any(re.search(pattern, value) for pattern in allow.get("regexes", [])),
                                 path + " must still detect a new hex-shaped token")


if __name__ == "__main__":
    unittest.main()
