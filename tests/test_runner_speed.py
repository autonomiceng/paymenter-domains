"""Scheduling and cancellation use real child processes, without a native fixture."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
spec = importlib.util.spec_from_file_location('speed_runner', ROOT/'scripts/test.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class SchedulingTests(unittest.TestCase):
    def test_short_categories_precede_native_and_profile_orders_within_category(self):
        commands = [('native', ['slow.php']), ('runner', ['guard.py']),
                    ('provider', ['provider.php']), ('native', ['fast.php']),
                    ('native', ['failed.php']), ('package', ['package.py'])]
        history = {'slow.php': (False, 20), 'fast.php': (False, 1), 'failed.php': (True, 30)}
        ordered = runner.order_commands(commands, history)
        self.assertEqual([argv[-1] for _, argv in ordered],
                         ['provider.php', 'guard.py', 'package.py', 'failed.php', 'fast.php', 'slow.php'])
        self.assertCountEqual(ordered, commands)

    def test_memory_budget_limits_workers_and_refuses_insufficient_memory(self):
        with mock.patch.object(runner, 'available_memory_mib', return_value=8192):
            self.assertEqual(runner.bounded_jobs(8, 2048), 2)
            self.assertEqual(runner.bounded_jobs(1, 4096), 1)
        with mock.patch.object(runner, 'available_memory_mib', return_value=1800):
            with self.assertRaises(ValueError):
                runner.bounded_jobs(2, 4096)

    def test_fail_fast_kills_running_process_group_and_discloses_unstarted_work(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root/'Domains').mkdir()
            output = root/'output'; output.mkdir()
            ready, escaped = root/'ready', root/'escaped'
            sleeper = ("import subprocess,sys,time; from pathlib import Path; "
                       f"subprocess.Popen([sys.executable,'-c',\"import time; from pathlib import Path; time.sleep(2); Path({str(escaped)!r}).touch()\"],start_new_session=True); "
                       f"Path({str(ready)!r}).touch(); print('started',flush=True); time.sleep(30)")
            failure = ("import time; from pathlib import Path; "
                       f"ready=Path({str(ready)!r}); deadline=time.monotonic()+5; "
                       "\nwhile not ready.exists() and time.monotonic()<deadline: time.sleep(.01)"
                       "\nassert ready.exists(); raise SystemExit(7)")
            commands = [('provider', [sys.executable, '-c', script]) for script in
                        [sleeper, failure, "raise RuntimeError('must not run')"]]
            started = time.monotonic()
            with mock.patch.object(runner, 'ROOT', root), contextlib.redirect_stdout(io.StringIO()):
                status = runner.run(commands, dict(os.environ), output, ['provider'], {}, jobs=2, fail_fast=True)
            self.assertEqual(status, 1)
            self.assertLess(time.monotonic()-started, 5)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertFalse(receipt['complete'])
            self.assertEqual(receipt['first_failure'], 1)
            self.assertTrue(receipt['results'][0]['cancelled'])
            self.assertEqual(receipt['results'][1]['exit'], 7)
            self.assertEqual(receipt['not_run'], [{'index': 2, 'kind': 'provider', 'argv': commands[2][1]}])
            self.assertEqual(receipt['commands_planned'], len(commands))
            self.assertIn('started', (output/'000.stdout.txt').read_text())
            time.sleep(2.1)
            self.assertFalse(escaped.exists(), 'Grandchild survived cancellation')


if __name__ == '__main__':
    unittest.main()
