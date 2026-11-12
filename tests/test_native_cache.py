# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Reuse only dependency bytes whose complete file identity is unchanged."""
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import native


class NativeCacheTests(unittest.TestCase):
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

    def test_unchanged_tree_avoids_walk_but_nested_mutation_invalidates_it(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root/'nested').mkdir()
            (root/'nested/file').write_bytes(b'original')
            expected = native.admitted_vendor_hashes(root)
            with mock.patch.object(native, '_vendor_inventory', wraps=native._vendor_inventory) as walk:
                self.assertEqual(expected, native.admitted_vendor_hashes(root))
                self.assertEqual(0, walk.call_count)
                (root/'nested/file').write_bytes(b'changed')
                self.assertNotEqual(expected, native.admitted_vendor_hashes(root))
                self.assertEqual(1, walk.call_count)

    def test_final_inventory_checks_memory_mapped_writes_without_notifications(self):
        import mmap
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root/'file').write_bytes(b'original')
            expected = native.admitted_vendor_hashes(root)
            with (root/'file').open('r+b') as stream, mmap.mmap(stream.fileno(), 0) as mapped:
                mapped[:] = b'modified'
                mapped.flush()
            self.assertNotEqual(expected, native.admitted_vendor_hashes(root, force=True))

    def test_watch_failure_and_overflow_never_reuse_the_inventory(self):
        from fixture_watch import UnchangedTree
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root/'file').write_bytes(b'original')
            native.admitted_vendor_hashes(root)
            watch = native._VENDOR_WATCHES[str(root)][0]
            with mock.patch('fixture_watch.os.read', return_value=b'overflow'), \
                 mock.patch.object(native, '_vendor_inventory', wraps=native._vendor_inventory) as walk:
                native.admitted_vendor_hashes(root)
                self.assertEqual(1, walk.call_count)
            self.assertFalse(watch.clean)
            with mock.patch.object(UnchangedTree, 'unchanged', return_value=False), \
                 mock.patch.object(native, '_vendor_inventory', wraps=native._vendor_inventory) as walk:
                native.admitted_vendor_hashes(root)
                self.assertEqual(1, walk.call_count)

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



if __name__ == '__main__':
    unittest.main()
