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
        with tempfile.TemporaryDirectory() as temporary:
            receipt = Path(temporary)/'receipt.json'
            rows = [{'kind': 'native', 'argv': ['php', path], 'exit': status, 'seconds': seconds}
                    for path, status, seconds in [('slow.php', 0, 20), ('fast.php', 0, 1), ('failed.php', 7, 30)]]
            rows.append(dict(rows[0], exit=-9, seconds=.01, cancelled=True))
            receipt.write_text(json.dumps({'results': rows}))
            previous = runner.history(receipt)
        self.assertEqual(previous[('native', 'slow.php')], (False, 20))
        ordered = runner.order_commands(commands, previous)
        self.assertEqual([argv[-1] for _, argv in ordered],
                         ['provider.php', 'guard.py', 'package.py', 'failed.php', 'fast.php', 'slow.php'])
        self.assertCountEqual(ordered, commands)

    def test_memory_budget_limits_workers_and_refuses_insufficient_memory(self):
        with mock.patch.object(runner, 'available_memory_mib', return_value=8192):
            self.assertEqual(runner.bounded_jobs(8, 2048), 2)
            self.assertEqual(runner.bounded_jobs(1, 4096), 1)
        with mock.patch.object(runner, 'available_memory_mib', return_value=1800):
            self.assertEqual(runner.bounded_jobs(1, 2048), 1)
            with self.assertRaises(ValueError):
                runner.bounded_jobs(1, 512)
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


    def test_unexpected_output_error_cancels_peers_without_fail_fast(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'Domains').mkdir()
            output = root/'output'; output.mkdir()
            ready, escaped = root/'ready', root/'escaped'
            sleeper = ("import subprocess,sys,time; from pathlib import Path; "
                       f"subprocess.Popen([sys.executable,'-c',\"import time; from pathlib import Path; time.sleep(2); Path({str(escaped)!r}).touch()\"],start_new_session=True); "
                       f"Path({str(ready)!r}).touch(); time.sleep(8)")
            success = ("import time; from pathlib import Path; "
                       f"ready=Path({str(ready)!r}); deadline=time.monotonic()+5; "
                       "\nwhile not ready.exists() and time.monotonic()<deadline: time.sleep(.01)"
                       "\nassert ready.exists()")
            commands = [('provider', [sys.executable, '-c', script]) for script in [sleeper, success]]
            started = time.monotonic()
            with mock.patch.object(runner, 'ROOT', root), \
                 mock.patch.object(Path, 'write_bytes', side_effect=OSError('output unavailable')), \
                 contextlib.redirect_stdout(io.StringIO()):
                status = runner.run(commands, dict(os.environ), output, ['provider'], {}, jobs=2)
            self.assertEqual(status, 1)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertEqual(receipt['runner_error'], 'OSError')
            self.assertFalse(receipt['complete'])
            self.assertEqual(len(receipt['results']), 2)
            self.assertEqual(receipt['not_run'], [])
            self.assertLess(time.monotonic()-started, 5)
            time.sleep(2.1)
            self.assertFalse(escaped.exists(), 'Grandchild survived output failure')


    def test_interrupt_allows_resource_owner_cleanup(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); ready, cleaned = root/'ready', root/'cleaned'
            child = ("import signal,time; from pathlib import Path; "
                     f"signal.signal(signal.SIGTERM,lambda *args:(Path({str(cleaned)!r}).touch(),exit(0))); "
                     f"Path({str(ready)!r}).touch(); time.sleep(30)")
            communicate = runner.subprocess.Popen.communicate
            calls = []
            def interrupt_once(process, *args, **kwargs):
                if not calls:
                    calls.append(True)
                    deadline = time.monotonic()+5
                    while not ready.exists() and time.monotonic()<deadline:
                        time.sleep(.01)
                    self.assertTrue(ready.exists())
                    raise KeyboardInterrupt
                return communicate(process, *args, **kwargs)
            with mock.patch.object(runner.subprocess.Popen, 'communicate', interrupt_once), self.assertRaises(KeyboardInterrupt):
                runner.execute([sys.executable, '-c', child], cwd=root, env=dict(os.environ), graceful=3, rss_path=root/'peak.rss')
            self.assertTrue(cleaned.exists(), 'Resource owner could not clean up on interruption')

    def test_fail_fast_owns_detached_child_after_its_parent_exits(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'Domains').mkdir()
            output = root/'output'; output.mkdir()
            ready, escaped = root/'ready', root/'escaped'
            orphan = ("import subprocess,sys,os; from pathlib import Path; "
                      f"subprocess.Popen([sys.executable,'-c',\"import time; from pathlib import Path; time.sleep(2); Path({str(escaped)!r}).touch()\"],start_new_session=True); "
                      f"Path({str(ready)!r}).write_text(str(os.getpid()))")
            failure = ("import time; from pathlib import Path; "
                       f"ready=Path({str(ready)!r}); deadline=time.monotonic()+5; "
                       "\nwhile (not ready.exists() or Path('/proc/'+ready.read_text()).exists()) and time.monotonic()<deadline: time.sleep(.01)"
                       "\nassert ready.exists(); raise SystemExit(7)")
            commands = [('provider', [sys.executable, '-c', script]) for script in [orphan, failure]]
            started = time.monotonic()
            with mock.patch.object(runner, 'ROOT', root), contextlib.redirect_stdout(io.StringIO()):
                status = runner.run(commands, dict(os.environ), output, ['provider'], {}, jobs=2, fail_fast=True)
            self.assertEqual(status, 1)
            self.assertLess(time.monotonic()-started, 5)
            time.sleep(2.1)
            self.assertFalse(escaped.exists(), 'Detached child survived parent exit and cancellation')


if __name__ == '__main__':
    unittest.main()
