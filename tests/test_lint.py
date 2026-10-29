"""Changed PHP selection uses real Git state and the pinned PHP subprocess runner."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
spec = importlib.util.spec_from_file_location('lint_runner', ROOT/'scripts/test.py')
runner = importlib.util.module_from_spec(spec); spec.loader.exec_module(runner)


class LintTests(unittest.TestCase):
    def repo(self):
        temp = tempfile.TemporaryDirectory(prefix='lint-selection-')
        self.addCleanup(temp.cleanup)
        root = Path(temp.name)
        for path in ['Domains', 'tests/Support', 'scripts']:
            (root/path).mkdir(parents=True)
        for path in ['Domains/changed.php', 'Domains/deleted.php', 'Domains/old.php', 'Domains/untouched.php',
                     'tests/changed.php']:
            (root/path).write_text('<?php // initial\n')
        for path in ['tests/php83-compatibility-test.php', 'tests/Support/Php83Compatibility.php', 'scripts/php-test']:
            shutil.copy2(ROOT/path, root/path)
        suites = {kind: [] for kind in runner.CATEGORIES if kind != 'lint'}
        suites['runner'] = ['tests/php83-compatibility-test.php']
        (root/'tests/suites.json').write_text(json.dumps(suites))
        self.git(root, 'init', '-q')
        self.git(root, 'add', '--', 'Domains', 'tests', 'scripts')
        self.git(root, 'commit', '-qm', 'Initial sources')
        return root

    def git(self, root, *args):
        return subprocess.check_output(['git', '-C', str(root), '-c', 'user.name=Test',
                                        '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false',
                                        *args], stderr=subprocess.PIPE).decode().strip()

    def test_committed_staged_unstaged_untracked_renamed_and_deleted_files(self):
        root = self.repo(); base = self.git(root, 'rev-parse', 'HEAD')
        (root/'Domains/changed.php').write_text('<?php // committed\n')
        self.git(root, 'add', '--', 'Domains/changed.php')
        self.git(root, 'commit', '-qm', 'Change source')
        self.git(root, 'mv', 'Domains/old.php', 'Domains/renamed space.php')
        (root/'Domains/deleted.php').unlink()
        (root/'tests/changed.php').write_text('<?php // unstaged\n')
        (root/'Domains/new\nline.php').write_text('<?php // untracked\n')
        (root/'Domains/note.txt').write_text('not PHP')
        with mock.patch.object(runner, 'ROOT', root):
            self.assertEqual(runner.lint_files(base), ['Domains/changed.php', 'Domains/new\nline.php',
                                                       'Domains/renamed space.php', 'tests/changed.php'])
            self.assertNotIn('Domains/changed.php', runner.lint_files('HEAD'))
            self.assertIn('Domains/untouched.php', runner.lint_files())
            for invalid in ['missing-ref', '--help']:
                with self.subTest(base=invalid), contextlib.redirect_stderr(io.StringIO()), \
                     self.assertRaises(subprocess.CalledProcessError):
                    runner.lint_files(invalid)

    def test_changed_lint_runs_real_php_and_discloses_scope_and_failures(self):
        root = self.repo(); runtime = None
        if os.environ.get('PAYMENTER_TEST_PHP'):
            binary = Path(os.environ['PAYMENTER_TEST_PHP'])
            extension = Path(os.environ['PAYMENTER_TEST_CURL_EXTENSION'])
        else:
            runtime, binary, extension = runner.prepare()
            self.addCleanup(shutil.rmtree, runtime)
        for index, (source, code) in enumerate([('<?php // valid\n', 0), ('<?php function (', 1)]):
            (root/'Domains/changed.php').write_text(source)
            output = root/f'output-{index}'; output.mkdir()
            with mock.patch.object(runner, 'ROOT', root), \
                 mock.patch.object(runner.sys, 'argv', ['test.py', '--suite', 'lint', '--lint-base', 'HEAD', '--jobs', '2']), \
                 mock.patch.object(runner.tempfile, 'mkdtemp', return_value=str(output)), \
                 mock.patch.object(runner, 'prepare', return_value=(None, binary, extension)), \
                 contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(runner.main(), code)
            receipt = json.loads((output/'receipt.json').read_text())
            self.assertEqual(receipt['lint_base'], 'HEAD')
            self.assertEqual(receipt['jobs'], 2)
            paths = [row['argv'][-1] for row in receipt['results']]
            self.assertEqual(paths[0], 'Domains/changed.php')
            if code == 0:
                self.assertEqual(paths, ['Domains/changed.php', 'tests/php83-compatibility-test.php'])
            else:
                self.assertIn(paths, [['Domains/changed.php'],
                                      ['Domains/changed.php', 'tests/php83-compatibility-test.php']])
            self.assertEqual(receipt['results'][0]['exit'] != 0, bool(code))
            self.assertEqual(receipt['complete'], not code)

    def test_partial_lint_requires_explicit_lint_suite(self):
        for selection in [[], ['--suite', 'provider'], ['--suite', 'lint', '--suite', 'provider'],
                          ['--only', 'tests/test_runner.py']]:
            with self.subTest(selection=selection), \
                 mock.patch.object(runner.sys, 'argv', ['test.py', '--lint-base', 'HEAD', *selection]), \
                 mock.patch.object(runner, 'prepare') as prepare, \
                 contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
                try: runner.main()
                finally: prepare.assert_not_called()


if __name__ == '__main__':
    unittest.main()
