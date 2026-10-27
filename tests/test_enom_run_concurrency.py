"""Offline multi-process SQLite admission, permit consumption and rollback checks."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class RunBudgetConcurrency(unittest.TestCase):
    def test_serialized_admission_and_single_consumption(self):
        with tempfile.TemporaryDirectory(prefix='run-budget-', dir=ROOT / '.cache') as directory:
            database = Path(directory) / 'budget.sqlite'
            database.touch()
            env = dict(os.environ, RUN_BUDGET_DB=str(database))

            def run(mode, *args):
                result = subprocess.run([str(ROOT / 'scripts/php-test'),
                                         str(ROOT / 'tests/Support/enom-run-concurrent.php'), mode, *args],
                                        env=env, capture_output=True, text=True, timeout=25, check=True)
                self.assertFalse(result.stderr, result.stderr)
                return result.stdout

            def race(mode, *args):
                with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                    return list(pool.map(lambda _: run(mode, *args), range(2)))

            self.assertIn('OK', run('setup'))
            self.assertTrue(all('OK' in result for result in race('admit')))
            self.assertIn('REFUSED', run('different'))
            permits = [result.split('PERMIT=', 1)[1].splitlines()[0] for result in race('reserve')]
            self.assertNotEqual(*permits)
            self.assertIn('REFUSED', run('reserve'))
            self.assertIn('REFUSED', run('fault', permits[0]))
            results = race('consume', permits[0])
            self.assertEqual(sum('OK' in result for result in results), 1, results)
            self.assertEqual(sum('REFUSED' in result for result in results), 1, results)
            self.assertIn('OK', run('consume', permits[1]))
            status = json.loads(run('status').split('STATUS=', 1)[1].splitlines()[0])
            self.assertEqual((status['used'], status['remaining'], status['rolling_used']), (2, 0, 2))


if __name__ == '__main__':
    unittest.main()
