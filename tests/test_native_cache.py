# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Read every dependency byte without retaining the verified vendor's page cache."""
import os
from pathlib import Path
import sys
import tempfile
import threading
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

    def test_shared_setup_retains_pages_only_until_the_second_complete_check(self):
        for shared in [False, True]:
            with self.subTest(shared=shared), tempfile.TemporaryDirectory() as temp:
                source = Path(temp) / 'source'; source.mkdir()
                git, lock = fixtures.NativeGuardTests().fixture(source)
                count = len(native.hashes(source / 'vendor'))
                phases = []
                verify = native.verify
                with mock.patch.object(native, 'git', side_effect=git), \
                        mock.patch.object(native, 'LOCK_SHA256', lock), \
                        mock.patch.object(native.os, 'posix_fadvise') as release:
                    def checked(*args, **kwargs):
                        result = verify(*args, **kwargs)
                        phases.append(release.call_count)
                        return result
                    with mock.patch.object(native, 'verify', side_effect=checked):
                        native.prepare(source, Path(temp) / 'copy', share_vendor=shared)
                self.assertEqual(phases, [0, count] if shared else [count, 2 * count])

    def test_shared_setup_rechecks_bytes_and_refuses_intervening_vendor_mutation(self):
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp) / 'source'; source.mkdir()
            git, lock = fixtures.NativeGuardTests().fixture(source)
            verify = native.verify
            phases = []
            def changed(*args, **kwargs):
                if phases:
                    (source / 'vendor/autoload.php').write_bytes(b'changed between complete checks')
                result = verify(*args, **kwargs)
                phases.append(result[1])
                return result
            with mock.patch.object(native, 'git', side_effect=git), \
                    mock.patch.object(native, 'LOCK_SHA256', lock), \
                    mock.patch.object(native, 'verify', side_effect=changed), \
                    self.assertRaisesRegex(RuntimeError, 'Dependency changed while copying'):
                native.prepare(source, Path(temp) / 'copy', share_vendor=True)
            self.assertEqual(len(phases), 2)
            self.assertNotEqual(phases[0], phases[1])

    def test_retained_shared_setup_keeps_only_php_until_the_final_complete_check(self):
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp) / 'source'; source.mkdir()
            git, lock = fixtures.NativeGuardTests().fixture(source)
            names = set(native.hashes(source / 'vendor'))
            assets = {name for name in names if not name.endswith('.php')}
            released = []
            def release(fd, offset, length, advice):
                path = Path(os.readlink(f'/proc/self/fd/{fd}'))
                released.append(path.relative_to(source / 'vendor').as_posix())
            with mock.patch.object(native, 'git', side_effect=git), \
                    mock.patch.object(native, 'LOCK_SHA256', lock), \
                    mock.patch.object(native.os, 'posix_fadvise', side_effect=release):
                snapshot = native.prepare(source, Path(temp) / 'copy', share_vendor=True,
                                          retain_vendor_cache=True)
                self.assertEqual(set(released), assets)
                self.assertEqual(len(released), len(assets))
                self.assertEqual(native.verify(source)[1], snapshot)
                self.assertEqual(set(released[len(assets):]), names)
                self.assertEqual(len(released), len(assets) + len(names))

    def test_retention_requires_shared_read_only_vendor(self):
        with tempfile.TemporaryDirectory() as temp, \
                mock.patch.object(native, 'verify') as verify:
            with self.assertRaisesRegex(ValueError, 'shared vendor'):
                native.prepare(Path(temp), Path(temp) / 'copy', retain_vendor_cache=True)
            verify.assert_not_called()

    def test_complete_inventory_reads_overlap_with_at_most_four_open_files(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            expected = {}
            for index in range(128):
                payload = ('synthetic dependency ' + str(index)).encode()
                (root / str(index)).write_bytes(payload)
                expected[str(index)] = native.hashlib.sha256(payload).hexdigest()
            digest = native.hashlib.file_digest
            lock = threading.Lock(); together = threading.Barrier(2, timeout=5)
            active = peak = entered = 0
            def read(handle, algorithm):
                nonlocal active, peak, entered
                with lock:
                    active += 1; peak = max(peak, active); entered += 1
                    first_pair = entered <= 2
                try:
                    if first_pair: together.wait()
                    return digest(handle, algorithm)
                finally:
                    with lock: active -= 1
            with mock.patch.object(native.hashlib, 'file_digest', side_effect=read):
                self.assertEqual(native.hashes(root), dict(sorted(expected.items())))
            self.assertEqual(entered, 128)
            self.assertGreaterEqual(peak, 2)
            self.assertLessEqual(peak, 4)

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
