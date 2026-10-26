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

    def test_suffix_download_and_pending_review_are_distinct(self):
        text = (ROOT/'README.md').read_text()
        self.assertIn('**Download now** starts a validated download.', text)
        self.assertIn('When a rejected replacement is pending, **Download now** opens **Use new list?**', text)

if __name__ == '__main__':
    unittest.main()
