"""Receipts separate cancelled tests from failures and record the tested Git tree."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import contextlib
import hashlib
import importlib.util
import io
import itertools
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
import case_budget

spec = importlib.util.spec_from_file_location('receipt_runner', ROOT/'scripts/test.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class ReceiptTests(unittest.TestCase):
    def test_bootstrap_inspects_offline_and_builds_from_verified_archives_on_request(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            scripts = root/'scripts'
            scripts.mkdir()
            for name in ('bootstrap.py', 'php_suite.py', 'php-suite.Dockerfile'):
                shutil.copyfile(ROOT/'scripts'/name, scripts/name)
            archive = b'verified archive fixture'
            lock = scripts/'php-runtime-packages.json'
            lock.write_text(json.dumps([{'url': 'https://example.test/php-native.deb', 'native': True,
                                         'sha256': hashlib.sha256(archive).hexdigest()}]))
            cache = root/'.cache/php-runtime'
            cache.mkdir(parents=True)
            (cache/'php-native.deb').write_bytes(archive)
            (cache/'unlisted.deb').write_bytes(b'outside the package lock')
            digest = hashlib.sha256((scripts/'php-suite.Dockerfile').read_bytes() + b'\0' + lock.read_bytes()).hexdigest()
            docker = root/'docker'
            docker.write_text("""#!/bin/sh
printf '%s\\n' "$*" >> "$CALLS"
if [ "$1 $2 $3" = 'image inspect --format' ]; then
    [ "$IMAGE_READY" = 1 ] || exit 1
    printf '%s\\n' sha256:prepared
elif [ "$1 $2 $3 $4" = 'build --platform linux/amd64 -t' ]; then
    [ "$(ls -A "$6/archives")" = php-native.deb ] || exit 99
    cmp "$PHP_RUNTIME_ARCHIVE_CACHE/php-native.deb" "$6/archives/php-native.deb" || exit 99
    cmp "$PACKAGE_LOCK" "$6/php-runtime-packages.json" || exit 99
    exit "$BUILD_STATUS"
else
    exit 99
fi
""")
            docker.chmod(0o700)
            calls = root/'calls'
            env = dict(os.environ, PATH=str(root)+os.pathsep+os.environ['PATH'], CALLS=str(calls),
                       PHP_RUNTIME_ARCHIVE_CACHE=str(cache), PACKAGE_LOCK=str(lock))
            tag = 'domains-php-suite:'+digest
            for flags in ([], ['--native']):
                for ready in (False, True):
                    with self.subTest(flags=flags, ready=ready):
                        calls.write_text('')
                        result = subprocess.run([sys.executable, str(scripts/'bootstrap.py'), *flags],
                                                env=dict(env, IMAGE_READY=str(int(ready))), capture_output=True,
                                                text=True, timeout=3)
                        self.assertEqual(calls.read_text().splitlines(), ['image inspect --format {{.Id}} '+tag])
                        self.assertEqual(result.returncode == 0, ready)
                        if not ready:
                            self.assertIn('--download', result.stderr)
                            self.assertFalse(result.stdout)
            for status in (0, 23):
                with self.subTest(build=status):
                    calls.write_text('')
                    result = subprocess.run([sys.executable, str(scripts/'bootstrap.py'), '--download'],
                                            env=dict(env, BUILD_STATUS=str(status), IMAGE_READY='1'), capture_output=True,
                                            text=True, timeout=3)
                    call, *after = calls.read_text().splitlines()
                    self.assertEqual(call.split()[:5], ['build', '--platform', 'linux/amd64', '-t', tag])
                    self.assertIn('php-build-', call.split()[5])
                    self.assertEqual(after, ['image inspect --format {{.Id}} '+tag] if not status else [])
                    self.assertEqual(result.returncode == 0, status == 0)
                    self.assertEqual('suite image ready' in result.stdout, status == 0)
                    if status:
                        self.assertIn('exit status 23', result.stderr)

    def test_hosted_bundle_uses_the_selected_runtime_even_with_a_different_runc_on_path(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            selected = root/'profiled-runc'
            selected.write_text("""#!/bin/sh
if [ "$1" = spec ]; then
    printf '%s' '{"linux":{"namespaces":[]},"process":{},"mounts":[]}' > config.json
elif [ "$4" = run ]; then
    mkdir -p "$2/$7" && exit 23
elif [ "$4" = delete ]; then
    rmdir "$2/$6"
else
    exit 99
fi
""")
            selected.chmod(0o700)
            shadow = root/'runc'
            shadow.write_text('#!/bin/sh\nexit 92\n')
            shadow.chmod(0o700)
            with mock.patch.dict(os.environ, DOMAINS_HOSTED_RUNC=str(selected), PATH=str(root)+':'+os.environ['PATH']):
                code = runner.php_suite.run_bundle(root, root/'rootfs', ['/bin/true'], root, {}, {})
            self.assertEqual(code, 23)
            self.assertEqual(list((root/'state').iterdir()), [])

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

    def test_container_setup_failure_leaves_an_incomplete_receipt(self):
        suite = runner.php_suite
        for reason in ('cgroup', 'dependency', 'alternates'):
            with self.subTest(reason=reason), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                group = root/'cgroup'/'fixture.scope'
                group.mkdir(parents=True)
                (group/'cgroup.procs').write_text(str(os.getpid()))
                (group/'cgroup.subtree_control').write_text('memory')
                app = root/'paymenter'
                app.mkdir()
                if reason == 'dependency':
                    (app/'.env').touch()
                expected = RuntimeError if reason == 'cgroup' else ValueError
                admitted = (contextlib.nullcontext() if reason == 'cgroup' else
                            mock.patch.object(suite, 'grant_scope', return_value=group))
                with admitted, mock.patch.dict(os.environ, {'PAYMENTER_TEST_APP': str(app)}, clear=True), \
                     mock.patch.object(suite, 'CGROUP', group.parent), \
                     mock.patch.object(suite, 'membership', return_value=Path('/fixture.scope')), \
                     mock.patch.object(tempfile, 'tempdir', directory), \
                     mock.patch.object(suite, 'git_inputs', side_effect=ValueError('Invalid Git alternate object directory.')), \
                     mock.patch.object(suite, 'image_root') as image, contextlib.redirect_stderr(io.StringIO()):
                    with self.assertRaises(expected) as failure:
                        suite.domains(root, [])
                if reason == 'cgroup':
                    self.assertIn('delegated memory controller', str(failure.exception))
                image.assert_not_called()
                receipt, = root.glob('domains-tests-*/receipt.json')
                self.assertEqual(json.loads(receipt.read_text()),
                                 dict(complete=False, phase='setup', runtime_unchanged=False))

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
        for launch in (False, True):
            with self.subTest(launch=launch), tempfile.TemporaryDirectory() as temp:
                output = Path(temp)
                with mock.patch.object(runner, 'git_tree', return_value=('a'*40, None)), \
                     mock.patch.object(runner, 'manifest', side_effect=ValueError('bad manifest')), \
                     mock.patch.object(runner.php_suite, 'IMAGE_ARCHIVES', output/'missing-archives'), \
                     mock.patch.object(runner.tempfile, 'mkdtemp', return_value=str(output)), \
                     mock.patch.dict(os.environ, {}, clear=True), \
                     mock.patch.object(runner.sys, 'argv', ['test.py']), contextlib.redirect_stderr(io.StringIO()), \
                     self.assertRaises(ValueError):
                    runner.main(launch=launch)
                receipt = json.loads((output/'receipt.json').read_text())
                self.assertFalse(receipt['complete'])
                self.assertEqual((receipt['phase'], receipt['git_tree'], receipt['git_tree_error']), ('setup', 'a'*40, None))

    def test_resources_are_recorded_only_after_the_cgroup_drains(self):
        for drained in (True, False):
            with self.subTest(drained=drained), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                receipt = root/'case.json'
                args = ['case_budget.py', '--name', 'probe', '--test-scope', '--wall', '10',
                        '--memory', '1048576', '--receipt', str(receipt), '--', 'probe']
                event_reads = []
                read_text = Path.read_text

                def execute(*args):
                    group, = root.glob('test-*')
                    (group/'memory.peak').write_text('4096')
                    (group/'memory.events').write_text('oom_kill 0\n')
                    (group/'cpu.stat').write_text('usage_usec 25000\n')
                    return subprocess.CompletedProcess(args, 0)

                def read(path, *args, **kwargs):
                    if path.name == 'cgroup.events':
                        self.assertEqual((path.parent/'cgroup.kill').read_text(), '1')
                        event_reads.append(path)
                        return 'populated 0\n' if drained and len(event_reads) > 1 else 'populated 1\n'
                    if path.name == 'memory.peak':
                        self.assertGreaterEqual(len(event_reads), 2)
                    return read_text(path, *args, **kwargs)

                def remove(group):
                    self.assertTrue(drained)
                    self.assertGreaterEqual(len(event_reads), 2)
                    self.assertFalse(receipt.exists())

                with mock.patch.object(sys, 'argv', args), mock.patch.dict(os.environ, DOMAINS_TEST_CGROUP=temp), \
                     mock.patch.object(case_budget, 'time', mock.Mock(monotonic=itertools.count(step=0.25).__next__)), \
                     mock.patch.object(case_budget.subprocess, 'run', side_effect=execute), \
                     mock.patch.object(case_budget.resource, 'getrusage', return_value=mock.Mock(ru_maxrss=8)), \
                     mock.patch.object(Path, 'read_text', read), mock.patch.object(Path, 'rmdir', remove):
                    if drained:
                        self.assertEqual(case_budget.main(), 0)
                    else:
                        with self.assertRaises(TimeoutError):
                            case_budget.main()
                self.assertEqual(receipt.exists(), drained)
                if drained:
                    data = json.loads(receipt.read_text())
                    self.assertEqual((data['wall_seconds'], data['memory_bytes'], data['cpu_seconds']), (0.25, 4096, 0.025))

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
