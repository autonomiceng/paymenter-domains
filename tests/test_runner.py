# Adapted from Enom tests/test_runner.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
spec = importlib.util.spec_from_file_location('domains_runner', ROOT/'scripts/test.py')
runner = importlib.util.module_from_spec(spec); spec.loader.exec_module(runner)


class InstalledPhaseTests(unittest.TestCase):
    def phase_failure(self, scenario, target, *, setup=0, earlier=0, expires=None, verify=0):
        spec = importlib.util.spec_from_file_location('installed_phase_limits', ROOT/'tests/test_installed_package.py')
        installed = importlib.util.module_from_spec(spec); spec.loader.exec_module(installed)
        clock, calls, errors = [1000.0], [], []
        def advance(seconds):
            clock[0] += seconds
        with tempfile.TemporaryDirectory() as temp:
            fixture = Path(temp)/'fixture'; (fixture/'bootstrap/cache').mkdir(parents=True)
            (fixture/'.domains-fixture.json').write_text('{}')
            def build_package(source, output):
                output.mkdir(); (output/'fixture.zip').write_bytes(b'offline archive boundary')
                advance(setup)
                return {'archive': 'fixture.zip', 'archive_sha256': 'synthetic'}
            def verify_package(*args):
                nonlocal verify
                advance(verify); verify = 0
            def child(argv, **options):
                name = options['env']['PACKAGE_PHASE']
                self.assertEqual(argv[-1], str(ROOT/'tests/package-lifecycle.php'))
                calls.append((name, options['timeout']))
                if name == target:
                    advance(options['timeout'] if expires is None else expires)
                    error = subprocess.TimeoutExpired(argv, options['timeout'])
                    errors.append(error)
                    raise error
                advance(earlier)
                return subprocess.CompletedProcess(argv, 0)
            read_text = Path.read_text
            def read(path, *args, **kwargs):
                return 'MemAvailable: 2000000 kB\n' if str(path) == '/proc/meminfo' else read_text(path, *args, **kwargs)
            # Replace this module's clock reference, leaving the runner and process clock real.
            with mock.patch.dict(os.environ, {'PAYMENTER_TEST_FIXTURE': str(fixture)}), \
                 mock.patch.object(installed, 'time', mock.Mock(monotonic=lambda: clock[0], sleep=advance)), \
                 mock.patch.object(installed.build, 'build', side_effect=build_package), \
                 mock.patch.object(installed.build, 'verify', side_effect=verify_package), \
                 mock.patch.object(installed, 'refuse_unsafe_archives'), \
                 mock.patch.object(installed.subprocess, 'run', side_effect=child), \
                 mock.patch.object(Path, 'read_text', read), contextlib.redirect_stdout(io.StringIO()) as output:
                with self.assertRaises(TimeoutError) as caught:
                    installed.main(scenario)
            timings = [json.loads(line) for line in output.getvalue().splitlines() if line.startswith('{')]
            self.assertTrue(any(row.get('timing') == 'phase_finish' and row['phase'] == target for row in timings))
            if errors:
                self.assertTrue(any(caught.exception.__cause__ is error for error in errors))
            else:
                self.assertIsNone(caught.exception.__cause__)
            return calls, str(caught.exception), timings

    def test_scenario_budget_caps_each_existing_phase_limit(self):
        for scenario, target, setup, earlier, expected in [
            ('held', 'install', 0, 0, [('install', 45)]),
            ('held', 'held', 0, 45, [('install', 45), ('held', 45)]),
            ('enom-test-management', 'install-enom-test-management', 0, 0, [('install-enom-test-management', 45)]),
        ]:
            with self.subTest(scenario=scenario, target=target):
                calls, error, _ = self.phase_failure(scenario, target, setup=setup, earlier=earlier)
                self.assertEqual(calls, expected)
                self.assertIn('exceeded 45 seconds', error)

    def test_remaining_budget_includes_setup_and_package_verification(self):
        for scenario, target, budget in [('held', 'install', 110), ('enom-test-management', 'install-enom-test-management', 60)]:
            for remaining in [10, 0.0005]:
                with self.subTest(scenario=scenario, remaining=remaining):
                    calls, error, _ = self.phase_failure(scenario, target, setup=budget-remaining-1, verify=1)
                    self.assertEqual([name for name, _ in calls], [target])
                    self.assertAlmostEqual(calls[0][1], remaining, places=10)
                    self.assertIn(f'exhausted the {budget}-second scenario deadline', error)

    def test_expired_scenario_refuses_php_before_first_and_later_phases(self):
        for scenario, first, later, budget in [('held', 'install', 'held', 110), ('enom-test-management', 'install-enom-test-management', 'enom-test-management-restart', 60)]:
            for overdue in [0, 1]:
                with self.subTest(scenario=scenario, overdue=overdue):
                    calls, error, _ = self.phase_failure(scenario, first, setup=budget+overdue)
                    self.assertEqual(calls, [], 'Expired first phase must not launch PHP')
                    self.assertIn(f'exhausted the {budget}-second scenario deadline', error)
                    calls, error, _ = self.phase_failure(scenario, later, setup=budget-45+overdue, earlier=45)
                    self.assertEqual(calls, [(first, 45-overdue)], 'Completed phases survive, expired next phase never launches')
                    self.assertIn(f'phase {later} exhausted', error)

    def test_timeout_label_uses_actual_expiry_and_preserves_cause(self):
        for scenario, target, budget in [('held', 'install', 110), ('enom-test-management', 'install-enom-test-management', 60)]:
            with self.subTest(scenario=scenario, expiry='future'):
                calls, error, _ = self.phase_failure(scenario, target, expires=45)
                self.assertEqual(calls, [(target, 45)])
                self.assertIn('exceeded 45 seconds', error)
                self.assertNotIn('scenario deadline', error)
            for elapsed in [10, 11]:
                with self.subTest(scenario=scenario, elapsed=elapsed):
                    calls, error, _ = self.phase_failure(scenario, target, setup=budget-10, expires=elapsed)
                    self.assertEqual(calls, [(target, 10)])
                    self.assertIn(f'exhausted the {budget}-second scenario deadline', error)

    def test_no_deadline_keeps_ordinary_limit_and_exact_special_phase_names(self):
        for scenario, target, expected in [
            ('fresh', 'install', [('install', 45)]),
            ('enom-test-renewal', 'enom-test-renewal', [('install', 45), ('enom-test-renewal', 120)]),
            ('enom-live', 'enom-live', [('install', 45), ('enom-live', 120)]),
            ('enom-test-renewal', 'enom-test-renewal-restart', [('install', 45), ('enom-test-renewal', 120), ('enom-test-renewal-restart', 45)]),
        ]:
            with self.subTest(scenario=scenario, target=target):
                calls, error, _ = self.phase_failure(scenario, target)
                self.assertEqual(calls, expected)
                self.assertIn(f'exceeded {expected[-1][1]} seconds', error)
                self.assertNotIn('scenario deadline', error)
        calls, error, _ = self.phase_failure('enom-test-renewal', 'enom-test-renewal-race-issue')
        self.assertEqual(calls[:3], [('install', 45), ('enom-test-renewal', 120), ('enom-test-renewal-restart', 45)])
        self.assertTrue(calls[3:])
        self.assertTrue(all(name == 'enom-test-renewal-race-issue' and timeout == 45 for name, timeout in calls[3:]))
        self.assertIn('exceeded 45 seconds', error)


class RunnerTests(unittest.TestCase):
    def test_default_reports_all_failures_but_stops_for_runtime_changes(self):
        for jobs in [1, 2]:
            for mutate in [False, True]:
                with self.subTest(jobs=jobs, mutate=mutate), tempfile.TemporaryDirectory() as temp:
                    root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
                    runtime = root/'Domains/Probe.php'; runtime.write_text('<?php // original')
                    commands = []
                    for index, code in enumerate([1, 0, 2, 0]):
                        # Exercise the installed-test barrier without building an application fixture.
                        path = root/('test_installed_probe.py' if index == 0 else f'probe{index}.py')
                        path.write_text(("from pathlib import Path; Path('Domains/Probe.php').write_text('<?php // changed'); "
                                         if mutate and index == 0 else '') + f'raise SystemExit({code})\n')
                        commands.append(('provider', [sys.executable, str(path)]))
                    with mock.patch.object(runner, 'ROOT', root), \
                         contextlib.redirect_stdout(io.StringIO()) as stdout:
                        self.assertEqual(runner.run(commands, dict(os.environ), output, ['provider'], {},
                                                    jobs=jobs), 1)
                    receipt = json.loads((output/'receipt.json').read_text())
                    expected = commands[:1] if mutate else commands
                    self.assertEqual([row['argv'] for row in receipt['results']], [argv for _, argv in expected])
                    self.assertFalse(receipt['complete'])
                    self.assertTrue(receipt['keep_going'])
                    summary = json.loads(stdout.getvalue().splitlines()[-1])
                    self.assertEqual([row['argv'] for row in summary['failed_commands']],
                                     [argv for index, (_, argv) in enumerate(expected) if index in [0, 2]])
                    self.assertEqual(summary['commands_run'], len(expected))
                    self.assertEqual(summary['commands_planned'], len(commands))
                    self.assertEqual(summary['failed_commands'][0]['runtime_unchanged'], not mutate)

    def test_ote_output_resolves_symlinked_tmpdir(self):
        spec = importlib.util.spec_from_file_location('ote_contract', ROOT/'scripts/ote-contract.py')
        ote = importlib.util.module_from_spec(spec); spec.loader.exec_module(ote)
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp).resolve(); target = root/'real'; target.mkdir()
            link = root/'alias'; link.symlink_to(target, target_is_directory=True)
            def child(argv, **options):
                output = Path(options['env']['OTE_OUTPUT'])
                # Exact equivalent of PHP realpath($output) === $output and private mode.
                self.assertEqual(str(output), os.path.realpath(output))
                self.assertEqual(output.stat().st_mode & 0o077, 0)
                (output/'receipt.json').write_text('{}')
                return subprocess.CompletedProcess(argv, 0)
            previous_mask = os.umask(0o077)
            try:
                with mock.patch.object(ote.tempfile, 'tempdir', str(link)), \
                     mock.patch.dict(os.environ, {'PAYMENTER_TEST_APP': ''}), \
                     mock.patch.object(sys, 'argv', ['ote-contract.py', '--self-test']), \
                     mock.patch.object(ote, 'prepare', return_value=(None, root/'php', root/'curl.so')), \
                     mock.patch.object(ote.subprocess, 'run', side_effect=child) as run, \
                     contextlib.redirect_stdout(io.StringIO()):
                    self.assertEqual(ote.main(), 0)
                    self.assertEqual(run.call_count, 1)
            finally:
                os.umask(previous_mask)

    def test_owned_renderer_rejects_unknown_mode_before_environment_or_files(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); assets = root/'assets'; assets.mkdir(); destination = root/'output'
            sentinel = assets/'ui33-owner.html'; sentinel.write_text('keep existing render')
            environment = dict(os.environ); environment.pop('PAYMENTER_TEST_APP', None)
            result = subprocess.run([sys.executable, str(ROOT/'scripts/render-owned.py'), str(assets),
                                     str(destination), 'record'], cwd=root, env=environment,
                                    capture_output=True, text=True, timeout=10)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(result.stderr.strip(), 'usage: render-owned.py SHELL_DIR OUTPUT_DIR [records]')
            self.assertEqual(result.stdout, '')
            self.assertEqual(sentinel.read_text(), 'keep existing render')
            self.assertEqual(list(root.iterdir()), [assets])
            self.assertEqual(list(assets.iterdir()), [sentinel])

    def test_parallel_receipt_and_output_keep_manifest_indexes(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            commands = [('provider', [str(index)]) for index in range(4)]
            def execute(argv, **kwargs):
                if argv == ['0']:
                    deadline = time.monotonic() + 5
                    while not (output/'001.stdout.txt').exists():
                        if time.monotonic() > deadline: raise AssertionError('Second command never completed')
                        time.sleep(0.01)
                return ('output '+argv[0]).encode(), ('error '+argv[0]).encode(), 0, False
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', side_effect=execute), \
                 contextlib.redirect_stdout(io.StringIO()) as stdout, contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(runner.run(commands, {}, output, ['provider'], {}, jobs=2), 0)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertTrue(receipt['complete']); self.assertEqual(receipt['jobs'], 2)
            self.assertEqual([row['argv'] for row in receipt['results']], [argv for _, argv in commands])
            self.assertLess(stdout.getvalue().index('output 1'), stdout.getvalue().index('output 0'))
            for index, row in enumerate(receipt['results']):
                self.assertGreaterEqual(row['seconds'], 0)
                self.assertEqual((output/f'{index:03d}.stdout.txt').read_text(), 'output '+str(index))
                self.assertEqual((output/f'{index:03d}.stderr.txt').read_text(), 'error '+str(index))

    def test_parallel_failure_stops_refill_and_receipts_inflight_success(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            commands = [('provider', [str(index)]) for index in range(8)]
            def execute(argv, **kwargs):
                if argv == ['0']:
                    deadline = time.monotonic() + 5
                    while not (output/'001.stdout.txt').exists():
                        if time.monotonic() > deadline: raise AssertionError('Failure never completed')
                        time.sleep(0.01)
                    return b'inflight finished', b'', 0, False
                self.assertEqual(argv, ['1'], 'A command was scheduled after failure')
                return b'failed', b'problem', 1, False
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', side_effect=execute) as called, \
                 contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(runner.run(commands, {}, output, ['provider'], {}, jobs=2, keep_going=False), 1)
            self.assertEqual(called.call_count, 2)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertFalse(receipt['complete'])
            self.assertEqual([row['exit'] for row in receipt['results']], [0, 1])
            self.assertEqual((output/'000.stdout.txt').read_bytes(), b'inflight finished')
            self.assertFalse((output/'002.stdout.txt').exists())

    def test_installed_commands_drain_pool_and_release_it_after_each_cycle(self):
        installed = ['tests/test_installed_package.py', 'tests/test_installed_package_enom_management.py',
                     'tests/test_installed_namesilo_enrollment.py']
        paths = ['before-0', 'before-1', *installed, 'between-0', 'between-1',
                 *installed, 'after-0', 'after-1']
        for jobs in [1, 2, 4]:
            with self.subTest(jobs=jobs), tempfile.TemporaryDirectory() as temp:
                root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
                active, lock, parallel = set(), threading.Lock(), threading.Event()
                pair = threading.Barrier(2, timeout=5) if jobs > 1 else None
                def execute(argv, **kwargs):
                    path = argv[-1]
                    with lock:
                        if path in installed:
                            self.assertFalse(active, 'Installed command overlapped earlier work')
                        else:
                            self.assertTrue(active.isdisjoint(installed), 'Work overlapped an installed command')
                        active.add(path)
                        if len(active) > 1:
                            parallel.set()
                    try:
                        if pair is not None and path in ['before-0', 'before-1']:
                            pair.wait()
                        return b'ok', b'', 0, False
                    finally:
                        with lock:
                            active.remove(path)
                commands = [('native', [sys.executable, path]) for path in paths]
                with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', side_effect=execute), \
                     contextlib.redirect_stdout(io.StringIO()):
                    self.assertEqual(runner.run(commands, {}, output, ['native'], {}, jobs=jobs), 0)
                results = json.loads((output/'receipt.json').read_text())['results']
                self.assertEqual([row['argv'][-1] for row in results], paths)
                self.assertEqual(parallel.is_set(), jobs > 1)
                for index, row in enumerate(results):
                    self.assertLess(row['started_seconds'], row['finished_seconds'])
                    if row['argv'][-1] in installed:
                        for other in results[:index]:
                            self.assertLessEqual(other['finished_seconds'], row['started_seconds'])
                        for other in results[index+1:]:
                            self.assertLessEqual(row['finished_seconds'], other['started_seconds'])

    def test_failure_before_or_during_installed_command_stops_at_barrier(self):
        commands = [('native', [path]) for path in ['before', 'tests/test_installed_package.py',
                                                    'tests/test_installed_namesilo_enrollment.py', 'after']]
        for failed in [0, 1]:
            with self.subTest(failed=failed), tempfile.TemporaryDirectory() as temp:
                root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
                def execute(argv, **kwargs):
                    return b'output', b'', int(argv == commands[failed][1]), False
                with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', side_effect=execute) as called, \
                     contextlib.redirect_stdout(io.StringIO()):
                    self.assertEqual(runner.run(commands, {}, output, ['native'], {}, jobs=4, keep_going=False), 1)
                receipt = json.loads((output/'receipt.json').read_text())
                self.assertFalse(receipt['complete'])
                self.assertEqual(called.call_count, failed+1)
                self.assertEqual([row['argv'] for row in receipt['results']], [argv for _, argv in commands[:failed+1]])

    def test_parallel_runtime_mutation_is_a_failure(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            path = root/'Domains/Probe.php'; path.write_text('<?php')
            def execute(argv, **kwargs):
                path.write_text('<?php // changed')
                return b'changed', b'', 0, False
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', side_effect=execute), \
                 contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.run([('provider', ['probe'])]*4, {}, output, ['provider'], {}, jobs=2), 1)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertFalse(receipt['complete'])
            self.assertTrue(any(not row['runtime_unchanged'] for row in receipt['results']))
            self.assertLessEqual(len(receipt['results']), 2)

    def test_final_runtime_guard_catches_a_late_change(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'hashes', side_effect=[{}, {}, {'late': 'change'}]), \
                 mock.patch.object(runner, 'execute', return_value=(b'ok', b'', 0, False)), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.run([('provider', ['probe'])], {}, output, ['provider'], {}), 1)
            self.assertFalse(json.loads((output/'receipt.json').read_text())['results'][0]['runtime_unchanged'])

    def test_parallel_runtime_write_then_restore_is_detected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            path = root/'Domains/Probe.php'; path.write_text('<?php')
            os.utime(path, ns=(1_000_000_000, 1_000_000_000))
            def execute(argv, **kwargs):
                path.write_text('changed'); path.write_text('<?php')
                return b'restored', b'', 0, False
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', side_effect=execute), \
                 contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.run([('provider', ['probe'])], {}, output, ['provider'], {}, jobs=2), 1)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertFalse(receipt['complete']); self.assertFalse(receipt['results'][0]['runtime_unchanged'])
            self.assertEqual(runner.native.hashes(root/'Domains'), receipt['runtime_sha256'])

    def test_worker_fixture_runtime_and_temporary_paths_are_private(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            fixture = root/'fixture'; (fixture/'vendor').mkdir(parents=True)
            (fixture/'vendor/autoload.php').write_text('<?php'); (fixture/'pristine').write_text('source')
            runtime = root/'runtime'; (runtime/'usr/bin').mkdir(parents=True)
            (runtime/'usr/bin/php8.4').write_text('binary')
            (runtime/'usr/bin/php').symlink_to('php8.4')
            env = {'PATH': os.environ['PATH'], 'PAYMENTER_TEST_FIXTURE': str(fixture),
                   'PAYMENTER_TEST_PHP': str(runtime/'usr/bin/php8.4')}
            workers, fixture_snapshot, runtime_snapshot = runner.test_workers.environments(env, output, 2, runtime)
            self.assertTrue(all(worker['PYTHONDONTWRITEBYTECODE'] == '1' for worker in workers))
            rendezvous = root/'ready'; rendezvous.mkdir()
            script = ("import os,time; from pathlib import Path; "
                      "fixture=Path(os.environ['PAYMENTER_TEST_FIXTURE']); token=str(os.getpid()); "
                      "(fixture/'state').write_text(token); "
                      "Path(os.environ['TMPDIR'],'state').write_text(token); "
                      f"ready=Path({str(rendezvous)!r}); (ready/token).touch(); deadline=time.monotonic()+5; "
                      "\nwhile len(list(ready.iterdir()))<2 and time.monotonic()<deadline: time.sleep(.01)"
                      "\nassert len(list(ready.iterdir()))==2"
                      "\nassert (fixture/'state').read_text()==token"
                      "\nassert Path(os.environ['TMPDIR'],'state').read_text()==token"
                      "\nprint(fixture)")
            with mock.patch.object(runner, 'ROOT', root), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.run([('runner', [sys.executable, '-c', script])]*2, env, output,
                                            ['runner'], {}, jobs=2, workers=workers), 0)
            self.assertNotEqual((output/'000.stdout.txt').read_bytes(), (output/'001.stdout.txt').read_bytes())
            self.assertFalse((fixture/'state').exists())
            for key in ['PAYMENTER_TEST_FIXTURE', 'PAYMENTER_TEST_PHP', 'TMPDIR']:
                self.assertNotEqual(workers[0][key], workers[1][key])
            runner.test_workers.verify(workers, fixture_snapshot, runtime_snapshot)
            self.assertTrue(Path(workers[1]['PAYMENTER_TEST_PHP']).with_name('php').is_symlink())
            Path(workers[1]['PAYMENTER_TEST_PHP']).write_text('changed')
            with self.assertRaises(RuntimeError): runner.test_workers.verify(workers, fixture_snapshot, runtime_snapshot)
            Path(workers[1]['PAYMENTER_TEST_PHP']).write_text('binary')
            Path(workers[1]['PAYMENTER_TEST_FIXTURE'], 'vendor/autoload.php').write_text('changed')
            with self.assertRaises(ValueError): runner.test_workers.verify(workers, fixture_snapshot, runtime_snapshot)

    def test_worker_copies_use_canonical_paths_under_a_linked_output_parent(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp).resolve()
            alias = root/'alias'; alias.symlink_to(root, target_is_directory=True)
            output = alias/'output'; output.mkdir()
            fixture = root/'fixture'; (fixture/'vendor').mkdir(parents=True)
            (fixture/'vendor/autoload.php').write_text('<?php')
            runtime = root/'runtime'; (runtime/'usr/bin').mkdir(parents=True)
            (runtime/'usr/bin/php8.4').write_text('binary')
            env = {'PAYMENTER_TEST_FIXTURE': str(fixture),
                   'PAYMENTER_TEST_PHP': str(runtime/'usr/bin/php8.4')}
            workers, fixture_snapshot, runtime_snapshot = runner.test_workers.environments(env, output, 2, runtime)
            for worker in workers:
                for key in ['PAYMENTER_TEST_FIXTURE', 'PAYMENTER_TEST_PHP', 'TMPDIR']:
                    path = Path(worker[key])
                    self.assertEqual(path, path.resolve())
                    self.assertTrue(path.is_relative_to(root/'output/workers'))
            runner.test_workers.verify(workers, fixture_snapshot, runtime_snapshot)

    def test_worker_dependency_guard_failure_preserves_results(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', return_value=(b'ok', b'', 0, False)), \
                 contextlib.redirect_stdout(io.StringIO()):
                code = runner.run([('provider', ['probe'])], {}, output, ['provider'], {}, jobs=2,
                                  worker_check=mock.Mock(side_effect=ValueError('worker changed')))
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertEqual(code, 1); self.assertEqual(len(receipt['results']), 1)
            self.assertFalse(receipt['dependency_unchanged']); self.assertEqual(receipt['dependency_error'], 'ValueError')

    def test_upgrade_revision_reaches_clean_worker_environment(self):
        with mock.patch.dict(os.environ, {'DOMAINS_UPGRADE_FROM': 'published^{commit}'}), \
             mock.patch.object(runner.sys, 'argv', ['test.py', '--jobs', '1']), \
             mock.patch.object(runner, 'default_suites', return_value=['provider']), \
             mock.patch.object(runner, 'manifest', return_value={'provider': ['tests/probe.php']}), \
             mock.patch.object(runner, 'prepare', return_value=(None, None, None)), \
             mock.patch.object(runner, 'run', return_value=0) as run:
            self.assertEqual(runner.main(), 0)
        self.assertEqual(run.call_args.args[1]['DOMAINS_UPGRADE_FROM'], 'published^{commit}')

    def test_jobs_environment_default_override_and_invalid_values(self):
        for arguments, environment, expected in [([], '2', 2), (['--jobs', '1'], '4', 1)]:
            with mock.patch.dict(os.environ, {'DOMAINS_TEST_JOBS': environment}), \
                 mock.patch.object(runner.sys, 'argv', ['test.py', *arguments]), \
                 mock.patch.object(runner, 'default_suites', return_value=['provider']), \
                 mock.patch.object(runner, 'manifest', return_value={'provider': ['tests/probe.php']}), \
                 mock.patch.object(runner, 'prepare', return_value=(None, None, None)), \
                 mock.patch.object(runner.test_workers, 'environments', return_value=([{}]*expected, None, None)), \
                 mock.patch.object(runner, 'run', return_value=0) as run:
                self.assertEqual(runner.main(), 0)
            self.assertEqual(run.call_args.kwargs['jobs'], expected)
        for value in ['0', '-1', 'invalid']:
            with mock.patch.object(runner.sys, 'argv', ['test.py', '--jobs', value]), \
                 contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit) as error:
                runner.main()
            self.assertEqual(error.exception.code, 2)

    def test_graceful_timeout_allows_resource_cleanup(self):
        code = "import signal,time; signal.signal(signal.SIGTERM,lambda *args:(print('cleaned',flush=True),exit(0))); time.sleep(30)"
        stdout, stderr, status, timed_out = runner.execute([sys.executable, '-c', code], cwd=ROOT, env=os.environ, timeout=2, graceful=3)
        self.assertEqual((status, timed_out, stdout, stderr), (124, True, b'cleaned\n', b''))

    def test_graceful_timeout_escalates_when_sigterm_is_ignored(self):
        code = "import os,signal,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); print(os.getpid(),flush=True); time.sleep(30)"
        started = time.monotonic()
        stdout, stderr, status, timed_out = runner.execute([sys.executable, '-c', code], cwd=ROOT,
                                                         env=os.environ, timeout=2, graceful=1)
        elapsed = time.monotonic() - started
        self.assertEqual((status, timed_out, stderr), (124, True, b''))
        self.assertGreaterEqual(elapsed, 3)
        self.assertLess(elapsed, 8)
        pid = int(stdout.strip())
        with self.assertRaises(ProcessLookupError):
            os.kill(pid, 0)

    def test_mariadb_non_skip_failure_is_receipted_as_failure(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); (root/'output').mkdir()
            with mock.patch.object(runner, 'ROOT', root), \
                 mock.patch.object(runner, 'execute', return_value=(b'failed', b'problem', 2, False)), \
                 contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(runner.run([('mariadb', ['probe'])], {}, root/'output', ['mariadb'], {}), 1)
            receipt = json.loads((root/'output/receipt.json').read_text())
            self.assertFalse(receipt['complete']); self.assertEqual(receipt['skipped_suites'], [])
            self.assertEqual(receipt['results'][0]['exit'], 2)
            self.assertFalse(receipt['results'][0]['skipped'])

    def test_mariadb_skip_cannot_mask_integrity_failures(self):
        for failure in ['dependency', 'dependency_error', 'worker', 'runtime', 'final_runtime']:
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
                before = {'Probe.php': 'original'}; changed = {'Probe.php': 'changed'}
                snapshots = [before, changed, changed] if failure == 'runtime' else [before, before, changed if failure == 'final_runtime' else before]
                verify = mock.Mock(side_effect=ValueError('changed')) if failure == 'dependency_error' else mock.Mock(return_value=(None, changed))
                worker_check = mock.Mock(side_effect=ValueError('worker changed')) if failure == 'worker' else None
                dependency = ('source', before) if failure.startswith('dependency') else None
                with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'hashes', side_effect=snapshots), \
                     mock.patch.object(runner.native, 'verify', verify), \
                     mock.patch.object(runner, 'execute', return_value=(b'unavailable', b'', 77, False)), \
                     contextlib.redirect_stdout(io.StringIO()):
                    self.assertEqual(runner.run([('mariadb', ['probe'])], {}, output, ['mariadb'], {},
                                                dependency, worker_check=worker_check), 1)
                receipt = json.loads((output/'receipt.json').read_text())
                self.assertFalse(receipt['complete'])
                self.assertEqual(receipt['dependency_unchanged'], failure in ['runtime', 'final_runtime'])
                self.assertEqual(receipt['results'][0]['runtime_unchanged'], failure not in ['runtime', 'final_runtime'])

    def test_mariadb_skip_is_not_acceptance(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); (root/'output').mkdir()
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', return_value=(b'image unavailable', b'', 77, False)), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.run([('mariadb', ['probe'])], {}, root/'output', ['mariadb'], {}, jobs=4), 77)
            receipt = json.loads((root/'output/receipt.json').read_text())
            self.assertFalse(receipt['complete'])
            self.assertEqual(receipt['skipped_suites'], ['mariadb'])
            self.assertTrue(receipt['results'][0]['skipped'])
            self.assertEqual(receipt['jobs'], 1)

    def test_mariadb_is_always_explicit_opt_in(self):
        for source in ['', '/synthetic/dependency']:
            with mock.patch.dict(os.environ, {'PAYMENTER_TEST_APP': source}):
                self.assertNotIn('mariadb', runner.default_suites())
                self.assertEqual('native' in runner.default_suites(), bool(source))
        self.assertIn('mariadb', runner.CATEGORIES)

    def test_failure_timeout_or_source_mutation_preserves_output_and_stop_policy(self):
        for failure, fail_fast in [('timeout', False), ('timeout', True), ('exit', True), ('mutation', False)]:
            with self.subTest(failure=failure, fail_fast=fail_fast), tempfile.TemporaryDirectory() as temp:
                root = Path(temp); (root/'Domains').mkdir(); (root/'output').mkdir()
                runtime = root/'Domains/Probe.php'; runtime.write_bytes(b'<?php')
                def execute(argv, **kwargs):
                    self.assertEqual(kwargs['timeout'], 60)
                    if failure == 'timeout':
                        return b'partial', b'problem', 124, True
                    if failure == 'mutation': runtime.write_bytes(b'<?php // changed')
                    return b'partial', b'problem', 1 if failure == 'exit' else 0, False
                with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', side_effect=execute) as called, \
                     contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    self.assertEqual(runner.run([('provider', ['php', 'probe']), ('provider', ['php', 'next'])], {}, root/'output', ['provider'], {},
                                                **({'keep_going': False} if fail_fast else {})), 1)
                expected = 1 if fail_fast or failure == 'mutation' else 2
                self.assertEqual(called.call_count, expected)
                receipt = json.loads((root/'output/receipt.json').read_text())
                self.assertFalse(receipt['complete'])
                self.assertEqual(len(receipt['results']), expected)
                self.assertEqual(receipt['keep_going'], not fail_fast)
                self.assertEqual(receipt['results'][0]['timed_out'], failure == 'timeout')
                self.assertEqual(receipt['results'][0]['runtime_unchanged'], failure != 'mutation')
                self.assertEqual((root/'output/000.stdout.txt').read_bytes(), b'partial')
                self.assertEqual((root/'output/000.stderr.txt').read_bytes(), b'problem')

    def test_manifest_rejects_missing_duplicate_and_unregistered_suites(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'tests').mkdir()
            (root/'tests/probe-test.php').write_text('<?php')
            suites = {name: [] for name in runner.CATEGORIES if name != 'lint'}
            for paths in [[], ['tests/missing-test.php'], ['tests/probe-test.php']*2]:
                suites['provider'] = paths
                (root/'tests/suites.json').write_text(json.dumps(suites))
                with mock.patch.object(runner, 'ROOT', root), self.assertRaises(ValueError): runner.manifest()
            suites['provider'] = ['tests/probe-test.php']
            (root/'tests/suites.json').write_text(json.dumps(suites))
            with mock.patch.object(runner, 'ROOT', root): self.assertEqual(runner.manifest(), suites)

    def test_final_dependency_guard_error_keeps_command_results(self):
        for error_type in [ValueError, KeyError, TypeError]:
            with self.subTest(error_type=error_type), tempfile.TemporaryDirectory() as temp:
                root = Path(temp); (root/'Domains').mkdir(); (root/'output').mkdir()
                with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', return_value=(b'ok', b'', 0, False)), \
                     mock.patch.object(runner.native, 'verify', side_effect=error_type('changed')), contextlib.redirect_stdout(io.StringIO()):
                    self.assertEqual(runner.run([('native', ['probe'])], {}, root/'output', ['native'], {}, ('source', {})), 1)
                receipt = json.loads((root/'output/receipt.json').read_text())
                self.assertEqual(receipt['results'][0]['exit'], 0)
                self.assertFalse(receipt['dependency_unchanged'])
                self.assertEqual(receipt['dependency_error'], error_type.__name__)

    def test_timeout_kills_nested_process_group(self):
        child_script = 'import os,time; print(os.getpid(),flush=True); time.sleep(15)'
        command = [sys.executable, '-c', f"import subprocess,sys,time; subprocess.Popen([sys.executable,'-c',{child_script!r}]); time.sleep(15)"]
        stdout, _, code, timed_out = runner.execute(command, cwd=ROOT, env=None, timeout=3)
        self.assertTrue(timed_out); self.assertEqual(code, 124)
        pid = stdout.decode().strip()
        self.assertTrue(pid.isdigit(), 'Nested child PID was not captured before timeout')
        child = Path('/proc')/pid/'stat'
        deadline = time.monotonic() + 3
        while True:
            if Path('/proc/self/stat').exists():
                try: state = child.read_text().split()[2]
                except (FileNotFoundError, ProcessLookupError): state = None
            else:
                try: os.kill(int(pid), 0); state = 'R'
                except ProcessLookupError: state = None
            if state in [None, 'Z', 'X'] or time.monotonic() >= deadline: break
            time.sleep(0.05)
        self.assertIn(state, [None, 'Z', 'X'], 'Nested child is still running')

    def test_escaped_session_pipe_drain_is_bounded_and_receipted(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); (root/'output').mkdir()
            pid_file = root/'escaped.pid'
            child_script = (f"import os,sys,time; from pathlib import Path; Path({str(pid_file)!r}).write_text(str(os.getpid())); "
                            "print('escaped-stdout',flush=True); print('escaped-stderr',file=sys.stderr,flush=True); time.sleep(15)")
            command = [sys.executable, '-c', f"import subprocess,sys,time; subprocess.Popen([sys.executable,'-c',{child_script!r}],start_new_session=True); time.sleep(15)"]
            execute = runner.execute
            started = time.monotonic()
            try:
                with mock.patch.object(runner, 'ROOT', root), \
                     mock.patch.object(runner, 'execute', side_effect=lambda argv, **kw: execute(argv, **dict(kw, timeout=3))), \
                     contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    self.assertEqual(runner.run([('runner', command)], None, root/'output', ['runner'], {}), 1)
                self.assertLess(time.monotonic() - started, 8, 'Escaped pipe owner blocked timeout cleanup')
                receipt = json.loads((root/'output/receipt.json').read_text())
                self.assertFalse(receipt['complete'])
                self.assertEqual(receipt['results'][0]['exit'], 124)
                self.assertTrue(receipt['results'][0]['timed_out'])
                self.assertEqual((root/'output/000.stdout.txt').read_bytes(), b'escaped-stdout\n')
                self.assertEqual((root/'output/000.stderr.txt').read_bytes(), b'escaped-stderr\n')
            finally:
                if pid_file.exists():
                    try: os.kill(int(pid_file.read_text()), signal.SIGKILL)
                    except ProcessLookupError: pass

    def test_registered_javascript_is_explicitly_refused(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'tests').mkdir(); (root/'tests/probe-test.cjs').write_text('throw Error()')
            suites = {name: [] for name in runner.CATEGORIES if name != 'lint'}
            suites['ui'] = ['tests/probe-test.cjs']; (root/'tests/suites.json').write_text(json.dumps(suites))
            with mock.patch.object(runner, 'ROOT', root), self.assertRaisesRegex(ValueError, 'JavaScript'):
                runner.manifest()

    def test_explicit_unimplemented_suite_is_not_green(self):
        with mock.patch.object(runner.sys, 'argv', ['test.py', '--suite', 'ui']), contextlib.redirect_stderr(io.StringIO()), self.assertRaises(ValueError):
            runner.main()

    def test_manifest_failure_has_setup_receipt(self):
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp)
            with mock.patch.object(runner, 'manifest', side_effect=ValueError('bad manifest')), \
                 mock.patch.object(runner.tempfile, 'mkdtemp', return_value=str(output)), \
                 mock.patch.object(runner.sys, 'argv', ['test.py']), contextlib.redirect_stderr(io.StringIO()), self.assertRaises(ValueError):
                runner.main()
            self.assertFalse(json.loads((output/'receipt.json').read_text())['complete'])

    def test_fingerprint_keeps_root_empty_directories_and_link_metadata_without_following(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); tree = root/'Domains'; tree.mkdir()
            (tree/'empty').mkdir(); (tree/'nested').mkdir()
            (tree/'nested/.hidden').write_bytes(b'original')
            outside = root/'outside'; outside.mkdir(); (outside/'file').write_bytes(b'outside')
            (tree/'directory-link').symlink_to(outside, target_is_directory=True)
            (tree/'broken').symlink_to(root/'missing'); (tree/'loop').symlink_to('loop')
            os.mkfifo(tree/'pipe')
            expected = {}
            for path in [tree, *tree.rglob('*')]:
                stat = path.lstat()
                expected[str(path.relative_to(tree))] = (stat.st_ino, stat.st_mode, stat.st_size,
                                                        stat.st_mtime_ns, stat.st_ctime_ns)
            with mock.patch.object(runner, 'ROOT', root):
                self.assertEqual(runner.runtime_fingerprint(), expected)
                self.assertNotIn('directory-link/file', expected)
                (tree/'nested/.hidden').chmod(0o400)
                self.assertNotEqual(runner.runtime_fingerprint(), expected)

    def test_lint_and_focused_receipts_include_timings_and_omissions(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            commands = [('lint', ['php', '-l', 'Domains/Probe.php']), ('provider', ['php', 'tests/probe-test.php'])]
            suites = {'provider': ['tests/probe-test.php', 'tests/other-test.php'], 'native': ['tests/native-test.php']}
            with mock.patch.object(runner, 'ROOT', root), \
                 mock.patch.object(runner, 'execute', return_value=(b'ok', b'', 0, False)), \
                 contextlib.redirect_stdout(io.StringIO()) as stdout:
                self.assertEqual(runner.run(commands, {}, output, ['lint', 'provider'], suites,
                                            only=['tests/probe-test.php']*2), 0)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertEqual(receipt['only_tests'], ['tests/probe-test.php'])
            self.assertEqual(receipt['omitted_tests'], ['tests/other-test.php', 'tests/native-test.php'])
            self.assertEqual(set(receipt['timings']), {'runtime_snapshot', 'commands', 'final_guards', 'run'})
            self.assertTrue(all(seconds >= 0 for seconds in receipt['timings'].values()))
            lines = [json.loads(line) for line in stdout.getvalue().splitlines() if line.startswith('{')]
            rows = [row for row in lines if row.get('timing') == 'command']
            self.assertEqual([row['index'] for row in rows], [0, 1])
            self.assertEqual([row['kind'] for row in rows], ['lint', 'provider'])
            for logged, saved in zip(rows, receipt['results']):
                self.assertGreaterEqual(saved['guard_seconds'], 0)
                for key in ['argv', 'seconds', 'guard_seconds', 'exit', 'timed_out']:
                    self.assertEqual(logged[key], saved[key])

    def test_only_paths_keep_manifest_order_and_native_fixture_requirements(self):
        suites = runner.manifest()
        cases = [(['tests/test_package.py'], ['package'], [], True),
                 (['tests/test_schema_mariadb.py'], ['mariadb'], ['--fail-fast'], False),
                 (['tests/test_package.py', 'tests/test_installed_package_previous.py', 'tests/test_package.py'],
                  ['native', 'package'], ['--keep-going'], True)]
        for requested, selected, policy, keep_going in cases:
            with self.subTest(requested=requested), tempfile.TemporaryDirectory() as temp, \
                 mock.patch.dict(os.environ, {'PAYMENTER_TEST_APP': 'pristine'}), \
                 mock.patch.object(runner.tempfile, 'mkdtemp', return_value=temp), \
                 mock.patch.object(runner.sys, 'argv', ['test.py', '--jobs', '1', *policy, '--only', *requested]), \
                 mock.patch.object(runner.native, 'prepare', return_value={'source': 'digest'}) as native_prepare, \
                 mock.patch.object(runner, 'prepare', return_value=(Path(temp)/'runtime', Path(temp)/'runtime/php', Path(temp)/'runtime/curl.so')) as php_prepare, \
                 mock.patch.object(runner, 'run', return_value=0) as run, contextlib.redirect_stdout(io.StringIO()):
                (Path(temp)/'runtime').mkdir()
                self.assertEqual(runner.main(), 0)
                expected = [(kind, path) for kind, paths in suites.items() for path in paths if path in requested]
                self.assertEqual([(kind, argv[-1]) for kind, argv in run.call_args.args[0]], expected)
                self.assertEqual(run.call_args.args[3], selected)
                if selected == ['mariadb']:
                    php_prepare.assert_not_called()
                else:
                    php_prepare.assert_called_once_with(native='native' in selected)
                self.assertEqual(native_prepare.call_count, int('native' in selected))
                self.assertEqual(run.call_args.kwargs['only'], requested)
                self.assertEqual(run.call_args.kwargs['keep_going'], keep_going)
                if 'native' in selected:
                    self.assertEqual(run.call_args.args[1]['PAYMENTER_TEST_FIXTURE'], str(Path(temp)/'paymenter'))
                    self.assertEqual(run.call_args.args[5], ('pristine', {'source': 'digest'}))

    def test_only_unknown_paths_and_missing_native_source_refuse_before_preparing(self):
        for path, message in [('tests/unregistered-test.php', 'Unregistered'),
                              ('../tests/test_runner.py', 'Unregistered'),
                              (str(ROOT/'tests/test_runner.py'), 'Unregistered'),
                              ('tests/test_installed_package_previous.py', 'PAYMENTER_TEST_APP')]:
            with self.subTest(path=path), tempfile.TemporaryDirectory() as temp, \
                 mock.patch.dict(os.environ, {'PAYMENTER_TEST_APP': ''}), \
                 mock.patch.object(runner.tempfile, 'mkdtemp', return_value=temp), \
                 mock.patch.object(runner.sys, 'argv', ['test.py', '--only', path]), \
                 mock.patch.object(runner, 'prepare') as prepare, mock.patch.object(runner.native, 'prepare') as native_prepare, \
                 contextlib.redirect_stderr(io.StringIO()), self.assertRaisesRegex(ValueError, message):
                try:
                    runner.main()
                finally:
                    prepare.assert_not_called(); native_prepare.assert_not_called()
                    receipt = json.loads((Path(temp)/'receipt.json').read_text())
                    self.assertFalse(receipt['complete']); self.assertEqual(receipt['only_tests'], [path])

    def test_conflicting_flags_and_only_manifest_validation(self):
        for arguments in [['--suite', 'runner', '--only', 'tests/test_runner.py'], ['--keep-going', '--fail-fast']]:
            with self.subTest(arguments=arguments), mock.patch.object(runner.sys, 'argv', ['test.py', *arguments]), \
                 mock.patch.object(runner.tempfile, 'mkdtemp') as output, contextlib.redirect_stderr(io.StringIO()), \
                 self.assertRaises(SystemExit) as caught:
                try: runner.main()
                finally: output.assert_not_called()
            self.assertEqual(caught.exception.code, 2)
        with tempfile.TemporaryDirectory() as temp, \
             mock.patch.object(runner.sys, 'argv', ['test.py', '--only', 'tests/test_runner.py']), \
             mock.patch.object(runner.tempfile, 'mkdtemp', return_value=temp), \
             mock.patch.object(runner, 'manifest', side_effect=ValueError('unregistered suite')), \
             mock.patch.object(runner, 'prepare') as prepare, contextlib.redirect_stderr(io.StringIO()), \
             self.assertRaisesRegex(ValueError, 'unregistered suite'):
            try: runner.main()
            finally: prepare.assert_not_called()

    def test_default_boundary_keeps_every_registered_command_and_lint(self):
        suites = runner.manifest()
        with tempfile.TemporaryDirectory() as temp, \
             mock.patch.object(runner.tempfile, 'mkdtemp', return_value=temp), \
             mock.patch.dict(os.environ, {'PAYMENTER_TEST_APP': 'pristine'}), \
             mock.patch.object(runner.sys, 'argv', ['test.py', '--jobs', '1']), \
             mock.patch.object(runner.native, 'prepare', return_value={}), \
             mock.patch.object(runner, 'prepare', return_value=(Path(temp)/'runtime', Path(temp)/'runtime/php', Path(temp)/'runtime/curl.so')), \
             mock.patch.object(runner, 'run', return_value=0) as run, contextlib.redirect_stdout(io.StringIO()):
            (Path(temp)/'runtime').mkdir()
            self.assertEqual(runner.main(), 0)
        commands = run.call_args.args[0]
        expected = [(kind, path) for kind, paths in suites.items() if kind != 'mariadb' for path in paths]
        self.assertEqual([(kind, argv[-1]) for kind, argv in commands if kind != 'lint'], expected)
        lint = [[str(ROOT/'scripts/php-test'), '-l', str(path.relative_to(ROOT))]
                for area in ['Domains', 'tests'] for path in sorted((ROOT/area).rglob('*.php'))]
        lint.append([str(ROOT/'scripts/php-test'), 'tests/php83-compatibility-test.php'])
        self.assertEqual([argv for kind, argv in commands if kind == 'lint'], lint)
        self.assertIsNone(run.call_args.kwargs['only'])
        self.assertTrue(run.call_args.kwargs['keep_going'])

    def test_conventions_exceptions_are_empty(self):
        doc = (ROOT/'docs/paymenter-extension-conventions.md').read_text()
        section = doc.split('## Known exceptions\n', 1)[1].split('\n## ', 1)[0].strip()
        self.assertEqual('There are no known exceptions.', section,
                         'Review and qualify any new architecture exception before changing this assertion.')

    def test_hygiene_and_physical_file_limit(self):
        paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
        private = {'.local', '.scratch', '.cache', 'private', 'backups', 'vendor', 'node_modules', 'dist', '__pycache__'}
        for name in filter(None, paths):
            path = ROOT/name
            self.assertFalse(private.intersection(Path(name).parts))
            self.assertFalse(path.is_symlink())
            if path.is_file(): self.assertLessEqual(len(path.read_bytes().splitlines()), 800, name)
        self.assertEqual((ROOT/'LICENSE').read_bytes(), (ROOT/'Domains/LICENSE').read_bytes())


if __name__ == '__main__':
    unittest.main()
