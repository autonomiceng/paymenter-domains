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
