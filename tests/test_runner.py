# Adapted from Enom tests/test_runner.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
spec = importlib.util.spec_from_file_location('domains_runner', ROOT/'scripts/test.py')
runner = importlib.util.module_from_spec(spec); spec.loader.exec_module(runner)


class RunnerTests(unittest.TestCase):
    def test_failure_timeout_or_source_mutation_saves_output_and_stops(self):
        for failure in ['timeout', 'exit', 'mutation']:
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
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
                    self.assertEqual(runner.run([('provider', ['php', 'probe']), ('provider', ['php', 'next'])], {}, root/'output', ['provider'], {}), 1)
                called.assert_called_once()
                receipt = json.loads((root/'output/receipt.json').read_text())
                self.assertFalse(receipt['complete'])
                self.assertEqual(len(receipt['results']), 1)
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
        command = [sys.executable, '-c', "import subprocess,sys,time; p=subprocess.Popen([sys.executable,'-c','import time; time.sleep(10)']); print(p.pid,flush=True); time.sleep(10)"]
        stdout, _, code, timed_out = runner.execute(command, cwd=ROOT, env=None, timeout=0.3)
        self.assertTrue(timed_out); self.assertEqual(code, 124)
        child = Path('/proc')/stdout.decode().strip()/'stat'
        try: state = child.read_text().split()[2]
        except (FileNotFoundError, ProcessLookupError): state = None
        self.assertIn(state, [None, 'Z'], 'Nested child is still running')

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
