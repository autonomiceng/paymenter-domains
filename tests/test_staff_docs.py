# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Public staff instructions match the available controls."""
from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[1]

class StaffDocs(unittest.TestCase):
    def test_runbook_names_both_enom_modes_without_endpoint_literals(self):
        text = (ROOT/'docs/release-runbook.md').read_text()
        self.assertNotRegex(text, r'https://(?:reseller|resellertest)\.enom\.com/interface\.asp')
        self.assertIn('Enom live API endpoint', text)
        self.assertIn('Enom TEST API endpoint', text)

if __name__ == '__main__':
    unittest.main()
