# MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic dependency guard cases.
import io
import os
import subprocess
import json
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]/'scripts'))
import native


class NativeGuardTests(unittest.TestCase):
    def fixture(self, root):
        package = {'name': 'synthetic/library', 'version': '1.0.0', 'dist': {'reference': 'synthetic'}}
        members = {'composer.lock': json.dumps({'packages': [package], 'packages-dev': []}).encode(),
                   'config/app.php': b"<?php return ['version' => 'development'];", 'bootstrap/app.php': b'<?php'}
        archive = io.BytesIO()
        with tarfile.open(fileobj=archive, mode='w') as tar:
            for name, payload in members.items():
                path = root/name; path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(payload)
                entry = tarfile.TarInfo(name); entry.size = len(payload); tar.addfile(entry, io.BytesIO(payload))
        (root/'vendor/composer').mkdir(parents=True)
        (root/'vendor/autoload.php').write_text('<?php')
        (root/'vendor/composer/installed.json').write_text(json.dumps({'packages': [package]}))
        def git(source, *args):
            if args == ('rev-parse', '--show-toplevel'): return str(root).encode()
            if args == ('rev-parse', 'HEAD'): return native.COMMIT.encode()
            return archive.getvalue()
        return git, native.hashlib.sha256(members['composer.lock']).hexdigest()

    def test_pristine_export_copies_without_modifying_dependency(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)/'source'; root.mkdir()
            git, lock = self.fixture(root)
            (root/'config/app.php').write_text("<?php return ['version' => '1.5.9'];")
            with mock.patch.object(native, 'git', side_effect=git), mock.patch.object(native, 'LOCK_SHA256', lock):
                before = native.hashes(root)
                native.prepare(str(root), Path(temp)/'copy')
                self.assertEqual(native.hashes(root), before)
                self.assertIn('development', (Path(temp)/'copy/config/app.php').read_text())
                self.assertTrue((Path(temp)/'copy/.domains-fixture.json').is_file())

    def test_installed_modified_missing_linked_and_wrong_vendor_rejected(self):
        for failure in ['env', 'cache', 'source', 'missing', 'symlink', 'vendor', 'commit', 'lock']:
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                root = Path(temp); git, lock = self.fixture(root)
                if failure == 'env': (root/'.env').write_text('synthetic')
                if failure == 'cache': (root/'bootstrap/cache').mkdir(); (root/'bootstrap/cache/config.php').write_text('<?php')
                if failure == 'source': (root/'config/app.php').write_text('<?php changed')
                if failure == 'missing': (root/'vendor/autoload.php').unlink()
                if failure == 'symlink': (root/'vendor/linked.php').symlink_to(root/'config/app.php')
                if failure == 'vendor': (root/'vendor/composer/installed.json').write_text('{"packages":[]}')
                if failure == 'commit':
                    original = git
                    git = lambda source, *args: b'wrong' if args == ('rev-parse', 'HEAD') else original(source, *args)
                if failure == 'lock': lock = 'wrong'
                with mock.patch.object(native, 'git', side_effect=git), mock.patch.object(native, 'LOCK_SHA256', lock), self.assertRaises(ValueError):
                    native.verify(root)

    def test_malformed_installed_metadata_is_validation_failure(self):
        for data in [{}, {'packages': None}, {'packages': [None]}, {'packages': [{}]},
                     {'packages': [{'name': 'synthetic/library', 'version': '1.0.0', 'dist': None}]}]:
            with self.subTest(data=data), tempfile.TemporaryDirectory() as temp:
                root = Path(temp); git, lock = self.fixture(root)
                (root/'vendor/composer/installed.json').write_text(json.dumps(data))
                with mock.patch.object(native, 'git', side_effect=git), mock.patch.object(native, 'LOCK_SHA256', lock), self.assertRaises(ValueError):
                    native.verify(root)

    @unittest.skipUnless(os.environ.get('PAYMENTER_TEST_FIXTURE'), 'native fixture not selected')
    def test_rendered_native_exception_has_failure_exit(self):
        root = Path(__file__).resolve().parents[1]
        result = subprocess.run([str(root/'scripts/php-test'), 'tests/native-fixture-test.php', '--fail-after-boot'],
                                cwd=root, capture_output=True, timeout=60)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'synthetic native failure', result.stdout + result.stderr)

    def test_relative_or_symlink_source_refused(self):
        with self.assertRaises(ValueError): native.verify(Path('relative'))
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'alias').symlink_to(root, target_is_directory=True)
            with self.assertRaises(ValueError): native.verify(root/'alias')


if __name__ == '__main__':
    unittest.main()
