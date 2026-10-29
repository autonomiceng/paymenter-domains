"""Offline multi-process SQLite checks: concurrent sends never pass the Enom TEST daily or per-minute limit."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import concurrent.futures
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]


class SendLimitConcurrency(unittest.TestCase):
    def test_concurrent_sends_admit_exactly_the_limit(self):
        with tempfile.TemporaryDirectory(prefix='send-limit-', dir=ROOT / '.cache') as directory:
            database = Path(directory) / 'limit.sqlite'
            database.touch()

            def run(mode, **limits):
                result = subprocess.run([str(ROOT / 'scripts/php-test'),
                                         str(ROOT / 'tests/Support/enom-send-limit-concurrent.php'), mode],
                                        env=dict(os.environ, SEND_LIMIT_DB=str(database), **limits),
                                        capture_output=True, text=True, timeout=60, check=True)
                self.assertFalse(result.stderr, result.stderr)
                return result.stdout

            def race(**limits):
                limits['LIMIT_START'] = str(time.time() + 15)
                with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
                    results = list(pool.map(lambda _: run('admit', **limits), range(6)))
                self.assertEqual(sum('ADMITTED' in result for result in results), 3, results)
                self.assertEqual(sum('REFUSED' in result for result in results), 3, results)

            self.assertIn('OK', run('setup'))
            race(LIMIT_DAY='3', LIMIT_MINUTE='100')  # first use, daily limit
            race(LIMIT_DAY='100', LIMIT_MINUTE='3', LIMIT_UID='Minute-UID')  # per-minute limit
            self.assertIn('SENDS=6 ACCOUNTS=2', run('count'))

if __name__ == '__main__':
    unittest.main()
