"""Receipts separate cancelled tests from failures and record the tested Git tree."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
spec = importlib.util.spec_from_file_location('receipt_runner', ROOT/'scripts/test.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class ReceiptTests(unittest.TestCase):
    def test_offline_bootstrap_only_inspects_the_prepared_image(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            scripts = root/'scripts'
            scripts.mkdir()
            for name in ('bootstrap.py', 'php_suite.py', 'php-suite.Dockerfile'):
                shutil.copyfile(ROOT/'scripts'/name, scripts/name)
            archive = b'verified archive fixture'
            lock = scripts/'php-runtime-packages.json'
            lock.write_text(json.dumps([{'url': 'https://example.invalid/php-native.deb', 'native': True,
                                         'sha256': hashlib.sha256(archive).hexdigest()}]))
            cache = root/'.cache/php-runtime'
            cache.mkdir(parents=True)
            (cache/'php-native.deb').write_bytes(archive)
            digest = hashlib.sha256((scripts/'php-suite.Dockerfile').read_bytes() + b'\0' + lock.read_bytes()).hexdigest()
            docker = root/'docker'
            docker.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$CALLS"\n'
                              '[ "$1 $2 $3" = "image inspect --format" ] || exit 99\n'
                              '[ "$IMAGE_READY" = 1 ] || exit 1\n'
                              'printf "%s\\n" "sha256:prepared"\n')
            docker.chmod(0o700)
            calls = root/'calls'
            for flags in ([], ['--native']):
                for ready in (False, True):
                    with self.subTest(flags=flags, ready=ready):
                        calls.write_text('')
                        env = dict(os.environ, PATH=str(root)+os.pathsep+os.environ['PATH'], CALLS=str(calls),
                                   IMAGE_READY=str(int(ready)))
                        result = subprocess.run([sys.executable, str(scripts/'bootstrap.py'), *flags], env=env,
                                                capture_output=True, text=True, timeout=3)
                        self.assertEqual(calls.read_text().splitlines(),
                                         ['image inspect --format {{.Id}} domains-php-suite:'+digest])
                        self.assertEqual(result.returncode == 0, ready)
                        if not ready:
                            self.assertIn('--download', result.stderr)
                            self.assertFalse(result.stdout)

    def test_container_success_requires_a_complete_receipt_on_host(self):
        suite = runner.php_suite
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            group = root/'cgroup'/'scope'
            group.mkdir(parents=True)
            (group/'cgroup.subtree_control').write_text('memory')
            def no_receipt(bundle, rootfs, argv, cwd, env, mounts, **options):
                return 0
            with mock.patch.dict(os.environ, {}, clear=True), mock.patch.object(suite, 'CGROUP', group.parent), \
                 mock.patch.object(suite, 'grant_scope', return_value=group), \
                 mock.patch.object(suite, 'membership', return_value=Path('/scope')), \
                 mock.patch.object(tempfile, 'tempdir', directory), \
                 mock.patch.object(suite, 'image_root', return_value=contextlib.nullcontext((root, 'image'))), \
                 mock.patch.object(suite, 'run_bundle', no_receipt), contextlib.redirect_stderr(io.StringIO()):
                with self.assertRaises(RuntimeError):
                    suite.domains(root, [])
            receipt, = root.glob('domains-tests-*/receipt.json')
            self.assertFalse(json.loads(receipt.read_text())['complete'])

    def test_fail_fast_cancelled_sibling_is_not_a_failure(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            commands = [('provider', [str(index)]) for index in range(6)]
            stopping = threading.Event()
            def cancel(processes):
                processes.cancelled.add(0); stopping.set()
            def execute(argv, **kwargs):
                if argv == ['0']:
                    # The sibling is still running when the failure stops the run.
                    if not stopping.wait(5): raise AssertionError('Failure never cancelled the sibling')
                    return b'', b'', -signal.SIGTERM, False
                return b'failed', b'problem', 1, False
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', side_effect=execute), \
                 mock.patch.object(runner.Processes, 'cancel', autospec=True, side_effect=cancel), \
                 contextlib.redirect_stdout(io.StringIO()) as stdout, contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(runner.run(commands, {}, output, ['provider'], {}, jobs=2, fail_fast=True), 1)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertFalse(receipt['complete'])
            self.assertEqual([row['exit'] for row in receipt['results']], [-signal.SIGTERM, 1])
            self.assertEqual(receipt['failures'], [1], 'A cancelled sibling is not a failure')
            self.assertEqual((receipt['first_failure'], receipt['cancelled']), (1, [0]))
            self.assertEqual([row['index'] for row in receipt['not_run']], [2, 3, 4, 5])
            summary = json.loads(stdout.getvalue().strip().splitlines()[-1])
            self.assertEqual((summary['failures'], summary['cancelled'], summary['not_run']), ([1], [0], 4))

    def test_receipt_records_git_tree_only_for_an_unchanged_clean_checkout(self):
        def git(repo, *args):
            return subprocess.run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid',
                                   '-c', 'commit.gpgsign=false', *args], cwd=repo, check=True,
                                  capture_output=True, text=True).stdout.strip()
        for case in ['pass', 'fail', 'dirty', 'changed', 'nested']:
            with self.subTest(case=case), tempfile.TemporaryDirectory() as temp:
                repo = Path(temp)/'repo'; (repo/'Domains').mkdir(parents=True); (repo/'Domains/Probe.php').write_text('<?php\n')
                output = Path(temp)/'output'; output.mkdir()
                git(repo, 'init', '-q'); git(repo, 'add', 'Domains/Probe.php'); git(repo, 'commit', '-q', '-m', 'probe')
                tree = git(repo, 'rev-parse', 'HEAD^{tree}')
                root = repo
                if case == 'dirty': (repo/'notes.txt').write_text('uncommitted')
                if case == 'nested': root = repo/'Domains'; (root/'Domains').mkdir()
                def execute(argv, **kwargs):
                    if case == 'changed':
                        (repo/'notes.txt').write_text('committed during the run')
                        git(repo, 'add', 'notes.txt'); git(repo, 'commit', '-q', '-m', 'notes')
                    return b'', b'', int(case == 'fail'), False
                with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', side_effect=execute), \
                     contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    code = runner.run([('provider', ['probe'])], {}, output, ['provider'], {}, checkout=runner.git_tree())
                receipt = json.loads((output/'receipt.json').read_text())
                self.assertEqual(code, 1 if case == 'fail' else 0)
                expected = {'pass': (tree, None), 'fail': (tree, None), 'dirty': (None, 'uncommitted changes'),
                            'changed': (None, 'checkout changed during the run'), 'nested': (None, 'not a Git checkout')}[case]
                self.assertEqual((receipt['git_tree'], receipt['git_tree_error']), expected)

    def test_direct_run_without_a_captured_checkout_records_no_tree(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'Domains').mkdir(); output = root/'output'; output.mkdir()
            with mock.patch.object(runner, 'ROOT', root), mock.patch.object(runner, 'execute', return_value=(b'', b'', 0, False)), \
                 mock.patch.object(runner, 'git_tree') as git_tree, contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.run([('provider', ['probe'])], {}, output, ['provider'], {}), 0)
            git_tree.assert_not_called()
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertEqual((receipt['git_tree'], receipt['git_tree_error']), (None, 'not recorded'))

    def test_setup_failure_receipt_records_the_checkout_tree(self):
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp)
            with mock.patch.object(runner, 'git_tree', return_value=('a'*40, None)), \
                 mock.patch.object(runner, 'manifest', side_effect=ValueError('bad manifest')), \
                 mock.patch.object(runner.tempfile, 'mkdtemp', return_value=str(output)), \
                 mock.patch.object(runner.sys, 'argv', ['test.py']), contextlib.redirect_stderr(io.StringIO()), \
                 self.assertRaises(ValueError):
                runner.main()
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertFalse(receipt['complete'])
            self.assertEqual((receipt['git_tree'], receipt['git_tree_error']), ('a'*40, None))

    def test_setup_failure_receipt_rechecks_the_checkout_tree(self):
        def git(repo, *args):
            return subprocess.run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid',
                                   '-c', 'commit.gpgsign=false', *args], cwd=repo, check=True,
                                  capture_output=True, text=True).stdout.strip()
        for case in ['unchanged', 'dirty', 'changed']:
            with self.subTest(case=case), tempfile.TemporaryDirectory() as temp:
                repo = Path(temp)/'repo'; (repo/'Domains').mkdir(parents=True); (repo/'Domains/Probe.php').write_text('<?php\n')
                output = Path(temp)/'output'; output.mkdir()
                git(repo, 'init', '-q'); git(repo, 'add', 'Domains/Probe.php'); git(repo, 'commit', '-q', '-m', 'probe')
                tree = git(repo, 'rev-parse', 'HEAD^{tree}')
                def failing_setup():
                    if case != 'unchanged':
                        (repo/'notes.txt').write_text('written during setup')
                    if case == 'changed':
                        git(repo, 'add', 'notes.txt'); git(repo, 'commit', '-q', '-m', 'notes')
                    raise ValueError('bad manifest')
                with mock.patch.object(runner, 'ROOT', repo), mock.patch.object(runner, 'manifest', side_effect=failing_setup), \
                     mock.patch.object(runner.tempfile, 'mkdtemp', return_value=str(output)), \
                     mock.patch.object(runner.sys, 'argv', ['test.py']), contextlib.redirect_stderr(io.StringIO()), \
                     self.assertRaises(ValueError):
                    runner.main()
                receipt = json.loads((output/'receipt.json').read_text())
                self.assertFalse(receipt['complete'])
                expected = {'unchanged': (tree, None), 'dirty': (None, 'uncommitted changes'),
                            'changed': (None, 'checkout changed during the run')}[case]
                self.assertEqual((receipt['git_tree'], receipt['git_tree_error']), expected)


    def test_missing_or_truncated_resources_preserve_later_native_failure(self):
        for jobs in (1, 2):
            for payload in (None, '', '{'):
                with self.subTest(jobs=jobs, payload=payload), tempfile.TemporaryDirectory() as temp:
                    root = Path(temp); (root/'Domains').mkdir()
                    output = root/'output'; output.mkdir()
                    commands = [('runner', ['probe', 'first']), ('native', ['probe', 'later'])]
                    def execute(argv, **kwargs):
                        path = Path(argv[argv.index('--receipt')+1])
                        if argv[-1] == 'first':
                            if payload is not None:
                                path.write_text(payload)
                            return b'first completed', b'', 0, False
                        path.write_text(json.dumps({'exit': 7, 'wall_seconds': 0.1, 'memory_bytes': 1}))
                        return b'later native failure', b'', 7, False
                    env = {'DOMAINS_TEST_CGROUP': '/unused', 'DOMAINS_TEST_BUDGET': 'unused'}
                    with mock.patch.object(runner, 'ROOT', root), \
                         mock.patch.object(runner, 'execute', side_effect=execute), \
                         contextlib.redirect_stdout(io.StringIO()):
                        status = runner.run(commands, env, output, ['runner', 'native'], {}, jobs=jobs)
                    receipt = json.loads((output/'receipt.json').read_text())
                    self.assertEqual(status, 1)
                    self.assertIsNone(receipt['runner_error'])
                    self.assertEqual(receipt['failures'], [0, 1])
                    self.assertEqual(receipt['not_run'], [])
                    self.assertEqual(receipt['results'][1]['exit'], 7)
                    self.assertIn('resource_error', receipt['results'][0])
                    self.assertIn('later native failure', (output/'001.stdout.txt').read_text())


if __name__ == '__main__':
    unittest.main()
