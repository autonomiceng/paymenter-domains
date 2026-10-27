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
    def test_serial_guard_interrupt_preserves_known_exit_and_captured_streams(self):
        import signal
        for signum in [signal.SIGTERM, signal.SIGINT]:
            with self.subTest(signal=signum), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary); (root/'Domains').mkdir()
                output = root/'output'; output.mkdir()
                original = runner.runtime_matches; calls = []
                def interrupt_once(*args):
                    if not calls:
                        calls.append(True)
                        os.kill(os.getpid(), signum)
                    return original(*args)
                child = "import sys; print('guard-stdout'); print('guard-stderr',file=sys.stderr)"
                previous = {number: signal.getsignal(number) for number in [signal.SIGTERM, signal.SIGINT]}
                try:
                    for number in previous:
                        signal.signal(number, runner.interrupted)
                    with mock.patch.object(runner, 'ROOT', root), \
                         mock.patch.object(runner, 'runtime_matches', interrupt_once), \
                         contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                        status = runner.run([('provider', [sys.executable, '-c', child])],
                                            dict(os.environ), output, ['provider'], {}, jobs=1)
                finally:
                    for number, handler in previous.items():
                        signal.signal(number, handler)
                self.assertEqual(status, 130)
                receipt = json.loads((output/'receipt.json').read_text())
                self.assertEqual(receipt['results'][0]['exit'], 0)
                self.assertFalse(receipt['results'][0]['runtime_unchanged'])
                self.assertFalse(receipt['results'][0]['guard_complete'])
                self.assertEqual(receipt['not_run'], [])
                self.assertEqual((output/'000.stdout.txt').read_text(), 'guard-stdout\n')
                self.assertEqual((output/'000.stderr.txt').read_text(), 'guard-stderr\n')

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


    def test_missing_kernel_child_list_refuses_before_spawning(self):
        spec = importlib.util.spec_from_file_location('ownership_probe', ROOT/'scripts/process_supervisor.py')
        supervisor = importlib.util.module_from_spec(spec); spec.loader.exec_module(supervisor)
        with mock.patch.object(sys, 'argv', ['supervisor', '--', 'unstarted']), \
             mock.patch.object(supervisor.ctypes, 'CDLL') as libc, \
             mock.patch.object(Path, 'read_text', side_effect=FileNotFoundError('no kernel child list')), \
             mock.patch.object(supervisor.subprocess, 'Popen') as spawn, \
             mock.patch.object(supervisor.os, 'waitpid', side_effect=[(123, 0), (0, 0)]), \
             mock.patch.object(supervisor.signal, 'signal'):
            libc.return_value.prctl.return_value = 0
            spawn.return_value.pid = 123
            with self.assertRaises(OSError):
                supervisor.main()
            spawn.assert_not_called()

    def test_empty_kernel_inventory_refuses_before_spawning(self):
        spec = importlib.util.spec_from_file_location('ownership_probe', ROOT/'scripts/process_supervisor.py')
        supervisor = importlib.util.module_from_spec(spec); spec.loader.exec_module(supervisor)
        with mock.patch.object(sys, 'argv', ['supervisor', '--', 'unstarted']), \
             mock.patch.object(supervisor.ctypes, 'CDLL') as libc, \
             mock.patch.object(Path, 'glob', return_value=[]), \
             mock.patch.object(supervisor.subprocess, 'Popen') as spawn, \
             mock.patch.object(supervisor.os, 'waitpid', side_effect=[(123, 0), (0, 0)]), \
             mock.patch.object(supervisor.signal, 'signal'):
            libc.return_value.prctl.return_value = 0
            spawn.return_value.pid = 123
            with self.assertRaises(OSError):
                supervisor.main()
            spawn.assert_not_called()

    def test_parallel_interrupt_preserves_captured_output(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'Domains').mkdir()
            output = root/'output'; output.mkdir()
            ready = [root/str(index) for index in range(2)]
            commands = [('provider', [sys.executable, '-c',
                        f"from pathlib import Path; import time; print('marker-{index}',flush=True); Path({str(path)!r}).touch(); time.sleep(30)"])
                        for index, path in enumerate(ready)]
            def interrupt(*args, **kwargs):
                deadline = time.monotonic()+5
                while not all(path.exists() for path in ready) and time.monotonic()<deadline:
                    time.sleep(.01)
                self.assertTrue(all(path.exists() for path in ready))
                raise KeyboardInterrupt
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'wait', interrupt), \
                 contextlib.redirect_stdout(io.StringIO()):
                status = runner.run(commands, dict(os.environ), output, ['provider'], {}, jobs=2)
            self.assertEqual(status, 130)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertEqual(len(receipt['results']), 2)
            self.assertEqual(receipt['not_run'], [])
            for index in range(2):
                self.assertIn(f'marker-{index}', (output/f'{index:03d}.stdout.txt').read_text())

    def test_serial_interrupt_preserves_captured_output(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'Domains').mkdir()
            output = root/'output'; output.mkdir(); ready = root/'ready'
            child = f"from pathlib import Path; import time; print('serial-marker',flush=True); Path({str(ready)!r}).touch(); time.sleep(30)"
            communicate = runner.subprocess.Popen.communicate
            calls = []
            def interrupt_once(process, *args, **kwargs):
                if not calls:
                    calls.append(True); deadline = time.monotonic()+5
                    while not ready.exists() and time.monotonic()<deadline:
                        time.sleep(.01)
                    self.assertTrue(ready.exists()); raise KeyboardInterrupt
                return communicate(process, *args, **kwargs)
            with mock.patch.object(runner, 'ROOT', root), \
                 mock.patch.object(runner.subprocess.Popen, 'communicate', interrupt_once), \
                 contextlib.redirect_stdout(io.StringIO()):
                status = runner.run([('provider', [sys.executable, '-c', child])], dict(os.environ), output, ['provider'], {})
            self.assertEqual(status, 130)
            self.assertIn('serial-marker', (output/'000.stdout.txt').read_text())

    def test_interrupt_between_output_writes_retries_both_streams(self):
        for jobs in [1, 2]:
            with self.subTest(jobs=jobs), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary); (root/'Domains').mkdir()
                output = root/'output'; output.mkdir()
                write = Path.write_bytes
                interrupted = []
                def interrupt_once(path, data):
                    if path.name == '000.stderr.txt' and not interrupted:
                        interrupted.append(True)
                        raise KeyboardInterrupt
                    return write(path, data)
                child = "import sys; print('stdout-marker'); print('stderr-marker',file=sys.stderr)"
                with mock.patch.object(runner, 'ROOT', root), \
                     mock.patch.object(Path, 'write_bytes', interrupt_once), \
                     contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    status = runner.run([('provider', [sys.executable, '-c', child])],
                                        dict(os.environ), output, ['provider'], {}, jobs=jobs)
                self.assertEqual(status, 130)
                self.assertEqual((output/'000.stdout.txt').read_text(), 'stdout-marker\n')
                self.assertEqual((output/'000.stderr.txt').read_text(), 'stderr-marker\n')
                receipt = json.loads((output/'receipt.json').read_text())
                self.assertEqual(len(receipt['results']), 1)
                self.assertFalse(receipt['complete'])


    def test_peer_cancellation_allows_cleanup_and_reaps_adopted_child(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'Domains').mkdir()
            output = root/'output'
            ready, cleaned, adopted, pidfile = [root/name for name in ['ready', 'cleaned', 'adopted', 'pid']]
            grandchild = "import os,signal,time; from pathlib import Path; signal.signal(signal.SIGTERM,signal.SIG_IGN); Path(%r).write_text(str(os.getpid())); time.sleep(30)" % str(pidfile)
            intermediate = "import subprocess,sys,signal,time; from pathlib import Path; subprocess.Popen([sys.executable,'-c',%r],start_new_session=True); signal.signal(signal.SIGTERM,lambda *args:(Path(%r).touch(),sys.exit(0))); time.sleep(30)" % (grandchild, str(adopted))
            slow = "import subprocess,sys,signal,time; from pathlib import Path; subprocess.Popen([sys.executable,'-c',%r],start_new_session=True); signal.signal(signal.SIGTERM,lambda *args:(Path(%r).touch(),sys.exit(0))); deadline=time.monotonic()+5\nwhile not Path(%r).exists() and time.monotonic()<deadline: time.sleep(.01)\nassert Path(%r).exists(); Path(%r).touch(); time.sleep(30)" % (intermediate, str(cleaned), str(pidfile), str(pidfile), str(ready))
            fail = "import time; from pathlib import Path; deadline=time.monotonic()+5\nwhile not Path(%r).exists() and time.monotonic()<deadline: time.sleep(.01)\nassert Path(%r).exists(); raise SystemExit(7)" % (str(ready), str(ready))
            output.mkdir()
            commands = [('provider', [sys.executable, '-c', code]) for code in [slow, fail]]
            with mock.patch.object(runner, 'ROOT', root), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.run(commands, dict(os.environ), output, ['provider'], {}, jobs=2, fail_fast=True), 1)
            self.assertTrue(cleaned.exists(), 'Command cleanup was skipped')
            self.assertTrue(adopted.exists(), 'Detached intermediate did not exit during cancellation')
            self.assertFalse(Path('/proc/'+pidfile.read_text()).exists(), 'Adopted child was not killed and reaped')

    def test_supervisor_ignores_repeated_signals_before_unwinding(self):
        spec = importlib.util.spec_from_file_location('signal_probe', ROOT/'scripts/process_supervisor.py')
        supervisor = importlib.util.module_from_spec(spec); spec.loader.exec_module(supervisor)
        handlers, snapshots = {}, []
        original = supervisor.Shutdown
        class ObservedShutdown(original):
            def __init__(self, signum):
                snapshots.append(dict(handlers))
                super().__init__(signum)
        def set_handler(signum, handler):
            handlers[signum] = handler
        def interrupt(*args):
            handlers[supervisor.signal.SIGTERM](supervisor.signal.SIGTERM, None)
        with mock.patch.object(sys, 'argv', ['supervisor', '--', 'unstarted']), \
             mock.patch.object(supervisor.ctypes, 'CDLL') as libc, \
             mock.patch.object(supervisor, 'children', return_value=set()), \
             mock.patch.object(supervisor, 'cleanup', return_value=True) as cleanup, \
             mock.patch.object(supervisor, 'Shutdown', ObservedShutdown), \
             mock.patch.object(supervisor.subprocess, 'Popen') as spawn, \
             mock.patch.object(supervisor.os, 'waitpid', side_effect=interrupt), \
             mock.patch.object(supervisor.signal, 'signal', side_effect=set_handler):
            libc.return_value.prctl.return_value = 0
            self.assertEqual(supervisor.main(), 143)
            cleanup.assert_called_once_with(spawn.return_value, 0)
        for signum in [supervisor.signal.SIGTERM, supervisor.signal.SIGINT]:
            self.assertEqual(snapshots[0][signum], supervisor.signal.SIG_IGN)

    def test_outer_runner_paired_signals_clean_up_and_receipt(self):
        import ctypes
        import signal
        import subprocess
        source = ROOT/'scripts/test.py'
        supervisor_path = source.with_name('process_supervisor.py')
        spec = importlib.util.spec_from_file_location('probe_cleanup', supervisor_path)
        owner = importlib.util.module_from_spec(spec); spec.loader.exec_module(owner)
        libc = ctypes.CDLL(None, use_errno=True); previous = ctypes.c_int()
        self.assertEqual(libc.prctl(37, ctypes.byref(previous), 0, 0, 0), 0)
        self.assertEqual(libc.prctl(36, 1, 0, 0, 0), 0)
        try:
            for trial in range(20):
                with self.subTest(trial=trial), tempfile.TemporaryDirectory() as temporary:
                    root = Path(temporary); (root/'Domains').mkdir()
                    ready, cleaned = root/'ready', root/'cleaned'
                    child = "import os,signal,sys,time; from pathlib import Path; signal.signal(signal.SIGTERM,lambda *a:(Path(%r).touch(),sys.exit(0))); Path(%r).write_text(str(os.getpid())); time.sleep(30)" % (str(cleaned), str(ready))
                    driver = ("import importlib.util,os,signal,sys; from pathlib import Path; "
                              "sys.path.insert(0,%r); spec=importlib.util.spec_from_file_location('subject',%r); subject=importlib.util.module_from_spec(spec); spec.loader.exec_module(subject); subject.ROOT=Path(%r); "
                              "signal.signal(signal.SIGTERM,subject.interrupted); signal.signal(signal.SIGINT,subject.interrupted); "
                              "output=subject.ROOT/'output'; output.mkdir(); raise SystemExit(subject.run([('provider',[sys.executable,'-c',%r])],dict(os.environ),output,['provider'],{},jobs=2))") % (str(source.parent), str(source), str(root), child)
                    try:
                        with (root/'stdout').open('wb') as out, (root/'stderr').open('wb') as err:
                            process = subprocess.Popen([sys.executable, '-c', driver], stdout=out, stderr=err, start_new_session=True)
                        deadline = time.monotonic()+5
                        while not ready.exists() and process.poll() is None and time.monotonic()<deadline:
                            time.sleep(.002)
                        self.assertTrue(ready.exists(), (root/'stderr').read_text())
                        signals = [signal.SIGTERM, signal.SIGINT]
                        if trial % 2:
                            signals.reverse()
                        for signum in signals:
                            os.kill(process.pid, signum)
                        status = process.wait(timeout=3)
                        receipt, = root.rglob('receipt.json')
                        self.assertEqual(status, 130, receipt.read_text())
                        self.assertTrue(cleaned.exists())
                        self.assertFalse(Path('/proc/'+ready.read_text()).exists())
                        receipt, = root.rglob('receipt.json')
                        self.assertFalse(json.loads(receipt.read_text())['complete'])
                    finally:
                        owner.cleanup(None)
        finally:
            libc.prctl(36, previous.value, 0, 0, 0)

if __name__ == '__main__':
    unittest.main()
