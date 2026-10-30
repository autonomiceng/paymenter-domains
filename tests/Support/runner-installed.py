# Adapted from Enom tests/test_runner.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]


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
