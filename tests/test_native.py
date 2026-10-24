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

    def test_hashes_track_nested_hidden_added_changed_and_removed_files(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'a').mkdir(); (root/'empty').mkdir()
            files = {'a/file': b'nested', 'a.txt': b'peer', '.hidden': b'hidden'}
            for name, payload in files.items(): (root/name).write_bytes(payload)
            expected = {name: native.hashlib.sha256(files[name]).hexdigest() for name in sorted(files, key=Path)}
            before = native.hashes(root)
            self.assertEqual(before, expected)
            self.assertEqual(list(before), list(expected))
            (root/'a/file').write_bytes(b'changed')
            self.assertNotEqual(native.hashes(root), before)
            (root/'a/file').write_bytes(files['a/file'])
            (root/'extra').write_bytes(b'added')
            self.assertNotEqual(native.hashes(root), before)
            (root/'extra').unlink(); (root/'.hidden').unlink()
            self.assertNotEqual(native.hashes(root), before)

    def test_hashes_refuse_file_directory_broken_and_looping_symlinks(self):
        for shape in ['file', 'directory', 'broken', 'loop', 'nested']:
            with self.subTest(shape=shape), tempfile.TemporaryDirectory() as temp:
                parent = Path(temp); root = parent/'tree'; root.mkdir()
                (parent/'outside').mkdir(); (parent/'outside/file').write_bytes(b'outside')
                targets = {'file': parent/'outside/file', 'directory': parent/'outside',
                           'broken': parent/'missing', 'loop': root/'linked', 'nested': parent/'outside/file'}
                link = root/'nested/linked' if shape == 'nested' else root/'linked'
                link.parent.mkdir(exist_ok=True); link.symlink_to(targets[shape])
                with self.assertRaisesRegex(ValueError, r'^Dependency symlinks are not accepted\.$'): native.hashes(root)

    def test_worker_fixture_keeps_pristine_files_and_exact_vendor_inventory(self):
        for failure in ['source', 'file_link', 'parent_link', 'vendor', 'added', 'missing', 'vendor_link', 'nested_link']:
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)/'fixture'; root.mkdir(); self.fixture(root)
                snapshot = native.hashes(root)
                (root/'generated').mkdir(); (root/'generated/cache.php').write_text('<?php')
                (root/'installed-extension').symlink_to(root/'generated')
                native.verify(root, fixture_snapshot=snapshot)
                if failure == 'source': (root/'config/app.php').write_text('changed')
                if failure == 'file_link':
                    path = root/'config/app.php'; saved = Path(temp)/'saved'; saved.write_bytes(path.read_bytes())
                    path.unlink(); path.symlink_to(saved)
                if failure in ['parent_link', 'vendor_link']:
                    path = root/('config' if failure == 'parent_link' else 'vendor')
                    saved = Path(temp)/'saved'; path.rename(saved); path.symlink_to(saved, target_is_directory=True)
                if failure == 'vendor': (root/'vendor/autoload.php').write_text('changed')
                if failure == 'added': (root/'vendor/extra.php').write_text('<?php')
                if failure == 'missing': (root/'vendor/autoload.php').unlink()
                if failure == 'nested_link': (root/'vendor/extra').symlink_to(root/'generated')
                expected = (r'Fixture dependency changed: config/app\.php' if failure == 'source'
                            else r'Fixture dependency path is linked\.' if failure in ['file_link', 'parent_link', 'vendor_link']
                            else r'Dependency symlinks are not accepted\.' if failure == 'nested_link'
                            else r'Fixture vendor inventory changed\.')
                with self.assertRaisesRegex(ValueError, '^'+expected+'$'): native.verify(root, fixture_snapshot=snapshot)

    @unittest.skipUnless(os.environ.get('PAYMENTER_TEST_FIXTURE'), 'native fixture not selected')
    def test_rendered_native_exception_has_failure_exit(self):
        root = Path(__file__).resolve().parents[1]
        result = subprocess.run([str(root/'scripts/php-test'), 'tests/native-fixture-test.php', '--fail-after-boot'],
                                cwd=root, capture_output=True, timeout=60)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'synthetic native failure', result.stdout + result.stderr)

    def test_relative_or_symlink_source_refused(self):
        with self.assertRaisesRegex(ValueError, r'^Use a canonical pristine development dependency\.$'): native.verify(Path('relative'))
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); (root/'alias').symlink_to(root, target_is_directory=True)
            with self.assertRaisesRegex(ValueError, r'^Use a canonical pristine development dependency\.$'): native.verify(root/'alias')


if __name__ == '__main__':
    unittest.main()
