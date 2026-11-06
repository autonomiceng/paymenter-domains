# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Read every dependency byte without retaining the verified vendor's page cache."""
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import native
import test_native as fixtures


class NativeCacheTests(unittest.TestCase):
    def test_verified_vendor_cache_is_released_only_after_each_complete_read(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            git, lock = fixtures.NativeGuardTests().fixture(root)
            expected = native.hashes(root / 'vendor')
            released = []

            def release(fd, offset, length, advice):
                path = Path(os.readlink(f'/proc/self/fd/{fd}'))
                self.assertTrue(path.is_relative_to(root / 'vendor'))
                self.assertEqual(os.lseek(fd, 0, os.SEEK_CUR), path.stat().st_size)
                self.assertEqual((offset, length, advice), (0, 0, os.POSIX_FADV_DONTNEED))
                released.append(path.relative_to(root / 'vendor').as_posix())

            with mock.patch.object(native, 'git', side_effect=git), \
                    mock.patch.object(native, 'LOCK_SHA256', lock), \
                    mock.patch.object(native.os, 'posix_fadvise', side_effect=release):
                _, snapshot = native.verify(root)
            self.assertEqual(sorted(released), sorted(expected))
            self.assertEqual({name.removeprefix('vendor/'): value for name, value in snapshot.items()
                              if name.startswith('vendor/')}, expected)
            self.assertEqual(native.hashes(root / 'vendor'), expected)

    def test_process_admission_reuses_only_unchanged_file_identity(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root/'file').write_bytes(b'original')
            with mock.patch.object(native.hashlib, 'file_digest', wraps=native.hashlib.file_digest) as digest:
                expected = native.admitted_vendor_hashes(root)
                self.assertEqual(native.admitted_vendor_hashes(root), expected)
                self.assertEqual(digest.call_count, 1, 'One full content admission per unchanged file')
                before = (root/'file').stat()
                (root/'file').write_bytes(b'modified')
                os.utime(root/'file', ns=(before.st_atime_ns, before.st_mtime_ns))
                self.assertNotEqual(native.admitted_vendor_hashes(root), expected,
                                    'Restoring mtime cannot hide a same-size edit')
                self.assertEqual(digest.call_count, 2)

    def test_process_admission_retains_exact_inventory_and_symlink_refusals(self):
        for change in ['add', 'remove', 'replace', 'link']:
            with self.subTest(change=change), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                (root/'file').write_bytes(b'original')
                expected = native.admitted_vendor_hashes(root)
                if change == 'add':
                    (root/'.hidden').write_bytes(b'added')
                elif change == 'remove':
                    (root/'file').unlink()
                elif change == 'replace':
                    (root/'next').write_bytes(b'modified')
                    (root/'next').replace(root/'file')
                else:
                    (root/'linked').symlink_to(root/'file')
                if change == 'link':
                    with self.assertRaisesRegex(ValueError, 'Dependency symlinks are not accepted'):
                        native.admitted_vendor_hashes(root)
                else:
                    self.assertNotEqual(native.admitted_vendor_hashes(root), expected)

    def test_ordinary_hashes_keep_the_callers_cache_policy(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'file').write_bytes(b'synthetic unchanged bytes')
            with mock.patch.object(native.os, 'posix_fadvise') as release:
                self.assertEqual(native.hashes(root), {
                    'file': native.hashlib.sha256(b'synthetic unchanged bytes').hexdigest()})
            release.assert_not_called()

    def test_unsupported_cache_hint_preserves_verification_and_mutation_refusal(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            git, lock = fixtures.NativeGuardTests().fixture(root)
            with mock.patch.object(native, 'git', side_effect=git), \
                    mock.patch.object(native, 'LOCK_SHA256', lock), \
                    mock.patch.object(native.os, 'posix_fadvise', side_effect=OSError('unsupported')):
                _, snapshot = native.verify(root)
                (root / 'vendor/autoload.php').write_bytes(b'changed dependency')
                with self.assertRaisesRegex(ValueError, 'Fixture vendor inventory changed'):
                    native.verify(root, fixture_snapshot=snapshot)


if __name__ == '__main__':
    unittest.main()
