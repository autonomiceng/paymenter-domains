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
    def test_category_rss_preserves_unknown_and_measured_zero(self):
        cases = [('lint', [None, None], None), ('provider', [None, None], None),
                 ('provider', [0], 0), ('provider', [None, 0, 2048], 2048)]
        for kind, peaks, expected in cases:
            with self.subTest(kind=kind, peaks=peaks), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary); (root/'Domains').mkdir()
                output = root/'output'; output.mkdir()
                commands = [(kind, ['php-test', *(['-l'] if kind == 'lint' else []), str(index)])
                            for index in range(len(peaks))]
                def execute(argv, **kwargs):
                    peak = peaks[int(argv[-1])]
                    if peak is not None:
                        kwargs['rss_path'].write_text(str(peak)+'\n')
                    return b'ok', b'', 0, False
                with mock.patch.object(runner, 'ROOT', root), \
                     mock.patch.object(runner, 'execute', side_effect=execute), \
                     contextlib.redirect_stdout(io.StringIO()):
                    self.assertEqual(runner.run(commands, {}, output, [kind], {}), 0)
                receipt = json.loads((output/'receipt.json').read_text())
                self.assertTrue(receipt['complete'])
                self.assertEqual([row['peak_rss_kib'] for row in receipt['results']], peaks)
                self.assertEqual(receipt['categories'][kind]['peak_child_rss_kib'], expected)

    def test_final_guard_interrupt_retains_run_receipt(self):
        import signal
        for target in ['runtime', 'dependency', 'worker']:
            for signum in [signal.SIGTERM, signal.SIGINT]:
                with self.subTest(target=target, signal=signum), tempfile.TemporaryDirectory() as temporary:
                    root = Path(temporary); (root/'Domains').mkdir()
                    output = root/'output'; output.mkdir()
                    original = runner.runtime_matches; calls = []
                    def interrupt(*args):
                        os.kill(os.getpid(), signum)
                    def interrupt_final(*args):
                        calls.append(True)
                        if target == 'runtime' and len(calls) == 2:
                            interrupt()
                        return original(*args)
                    previous = {number: signal.getsignal(number) for number in [signal.SIGTERM, signal.SIGINT]}
                    try:
                        for number in previous:
                            signal.signal(number, runner.interrupted)
                        with mock.patch.object(runner, 'ROOT', root), \
                             mock.patch.object(runner, 'runtime_matches', interrupt_final), \
                             mock.patch.object(runner.native, 'verify', side_effect=interrupt), \
                             contextlib.redirect_stdout(io.StringIO()):
                            try:
                                status = runner.run([('provider', [sys.executable, '-c', "print('completed')"])],
                                                    dict(os.environ), output, ['provider'], {}, jobs=1,
                                                    dependency=(root, 'saved') if target == 'dependency' else None,
                                                    worker_check=interrupt if target == 'worker' else None)
                            except KeyboardInterrupt:
                                self.fail('Final verification interrupt escaped without a run receipt')
                    finally:
                        for number, handler in previous.items():
                            signal.signal(number, handler)
                    self.assertEqual(status, 130)
                    receipt = json.loads((output/'receipt.json').read_text())
                    self.assertEqual(receipt['results'][0]['exit'], 0)
                    self.assertTrue(receipt['results'][0]['guard_complete'])
                    self.assertEqual(receipt['not_run'], [])
                    self.assertEqual(len(receipt['plan']), 1)
                    self.assertFalse(receipt['final_guard_complete'])
                    self.assertEqual(receipt['final_guard_error'], 'KeyboardInterrupt')
                    self.assertFalse(receipt['complete'])
                    self.assertEqual((output/'000.stdout.txt').read_text(), 'completed\n')

    def test_receipt_write_interrupt_retries_with_incomplete_run(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'Domains').mkdir()
            output = root/'output'; output.mkdir()
            original = Path.write_text; calls = []
            def interrupt_once(path, *args, **kwargs):
                if path.name == 'receipt.json' and not calls:
                    calls.append(True)
                    raise KeyboardInterrupt
                return original(path, *args, **kwargs)
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(Path, 'write_text', interrupt_once), \
                 contextlib.redirect_stdout(io.StringIO()):
                try:
                    status = runner.run([('provider', [sys.executable, '-c', "print('completed')"])],
                                        dict(os.environ), output, ['provider'], {}, jobs=1)
                except KeyboardInterrupt:
                    self.fail('Receipt-write interrupt lost completed execution evidence')
            self.assertEqual(status, 130)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertEqual(receipt['results'][0]['exit'], 0)
            self.assertTrue(receipt['final_guard_complete'])
            self.assertFalse(receipt['complete'])
            self.assertEqual(receipt['runner_error'], 'KeyboardInterrupt')

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
            self.assertEqual(runner.bounded_jobs(4, 2048), 1)
            with self.assertRaises(ValueError):
                runner.bounded_jobs(1, 512)
            with self.assertRaisesRegex(ValueError, 'only 1 worker'):
                runner.bounded_jobs(2, 4096, explicit=True)
        with mock.patch.object(runner, 'available_memory_mib', return_value=8192):
            with self.assertRaisesRegex(ValueError, 'only 2 worker'):
                runner.bounded_jobs(4, 2048, explicit=True)

    def test_available_memory_uses_cgroup_headroom_when_lower(self):
        mib = 1024*1024
        cases = [('2 GiB limit', {'memory.max': f'{2048*mib}\n', 'memory.current': f'{512*mib}\n'}, 1536),
                 ('reclaimable cache', {'memory.max': f'{2048*mib}\n', 'memory.current': f'{1536*mib}\n',
                                        'memory.stat': f'anon 1\ninactive_file {512*mib}\n'}, 1024),
                 ('over limit', {'memory.max': f'{1024*mib}\n', 'memory.current': f'{1100*mib}\n'}, 0),
                 ('no limit', {'memory.max': 'max\n', 'memory.current': f'{512*mib}\n'}, 16384),
                 ('limit above host', {'memory.max': f'{65536*mib}\n', 'memory.current': '0\n'}, 16384),
                 ('missing files', {}, 16384),
                 ('missing current', {'memory.max': f'{2048*mib}\n'}, 16384),
                 ('malformed limit', {'memory.max': 'lots\n', 'memory.current': '0\n'}, 16384),
                 ('malformed stat', {'memory.max': f'{2048*mib}\n', 'memory.current': f'{512*mib}\n',
                                     'memory.stat': 'inactive_file many\n'}, 16384)]
        for name, files, expected in cases:
            with self.subTest(name), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                meminfo = root/'meminfo'
                meminfo.write_text('MemTotal: 33554432 kB\nMemAvailable: 16777216 kB\n')
                cgroup = root/'cgroup'; cgroup.mkdir()
                for filename, content in files.items():
                    (cgroup/filename).write_text(content)
                self.assertEqual(runner.available_memory_mib(meminfo=meminfo, cgroup=cgroup), expected)

    def test_implicit_jobs_reduce_to_serial_and_record_memory_reduction(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'Domains').mkdir()
            output = root/'output'; output.mkdir()
            environment = dict(os.environ)
            environment.pop('DOMAINS_TEST_JOBS', None)
            with mock.patch.dict(os.environ, environment, clear=True), \
                 mock.patch.object(runner.sys, 'argv', ['test.py']), \
                 mock.patch.object(runner, 'ROOT', root), \
                 mock.patch.object(runner.os, 'cpu_count', return_value=8), \
                 mock.patch.object(runner, 'available_memory_mib', return_value=1800), \
                 mock.patch.object(runner.tempfile, 'mkdtemp', return_value=str(output)), \
                 mock.patch.object(runner, 'default_suites', return_value=['provider']), \
                 mock.patch.object(runner, 'manifest', return_value={'provider': ['tests/probe.php']}), \
                 mock.patch.object(runner, 'prepare', return_value=(None, None, None)), \
                 mock.patch.object(runner, 'execute', return_value=(b'ok', b'', 0, False)), \
                 mock.patch.object(runner.test_workers, 'environments') as workers, \
                 contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.main(), 0)
            workers.assert_not_called()
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertTrue(receipt['complete'])
            self.assertEqual(receipt['jobs'], 1)
            self.assertEqual(receipt['requested_jobs'], 4)
            self.assertFalse(receipt['jobs_explicit'])
            self.assertEqual(receipt['jobs_reduction'], {'requested': 4, 'actual': 1, 'reason': 'memory'})

    def test_explicit_cli_and_environment_jobs_refuse_insufficient_memory(self):
        for arguments, environment in [(['--jobs', '2'], {}), ([], {'DOMAINS_TEST_JOBS': '2'})]:
            with self.subTest(arguments=arguments, environment=environment), tempfile.TemporaryDirectory() as temporary:
                output = Path(temporary)
                with mock.patch.dict(os.environ, environment, clear=True), \
                     mock.patch.object(runner.sys, 'argv', ['test.py', *arguments]), \
                     mock.patch.object(runner, 'available_memory_mib', return_value=1800), \
                     mock.patch.object(runner.tempfile, 'mkdtemp', return_value=str(output)), \
                     mock.patch.object(runner, 'default_suites', return_value=['provider']), \
                     mock.patch.object(runner, 'manifest', return_value={'provider': ['tests/probe.php']}), \
                     mock.patch.object(runner, 'prepare', return_value=(None, None, None)), \
                     mock.patch.object(runner, 'run') as run, contextlib.redirect_stdout(io.StringIO()), \
                     contextlib.redirect_stderr(io.StringIO()), self.assertRaisesRegex(ValueError, 'only 1 worker'):
                    runner.main()
                run.assert_not_called()
                receipt = json.loads((output/'receipt.json').read_text())
                self.assertFalse(receipt['complete'])
                self.assertEqual(receipt['phase'], 'setup')

    def test_only_syntax_lint_bypasses_supervisor_and_rss_measurement(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'Domains').mkdir()
            output = root/'output'; output.mkdir()
            commands = [('lint', ['php-test', '-l', 'Domains/Probe.php']),
                        ('lint', ['php-test', 'tests/php83-compatibility-test.php']),
                        ('provider', ['php-test', 'tests/probe-test.php'])]
            with mock.patch.object(runner, 'ROOT', root), \
                 mock.patch.object(runner.subprocess, 'Popen') as popen, contextlib.redirect_stdout(io.StringIO()):
                process = popen.return_value.__enter__.return_value
                process.communicate.return_value = (b'ok', b'')
                process.returncode = 0
                self.assertEqual(runner.run(commands, {}, output, ['lint', 'provider'], {}), 0)
            invocations = [call.args[0] for call in popen.call_args_list]
            self.assertEqual(invocations[0], commands[0][1])
            for invocation, (_, argv) in zip(invocations[1:], commands[1:]):
                self.assertEqual(invocation[:2], [sys.executable, str(runner.SUPERVISOR)])
                self.assertIn('/usr/bin/time', invocation)
                self.assertEqual(invocation[-len(argv):], argv)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertTrue(receipt['complete'])
            self.assertIsNone(receipt['results'][0]['peak_rss_kib'])
            self.assertFalse((output/'000.rss.txt').exists())

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
