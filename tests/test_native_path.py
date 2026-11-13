"""Native fixture helpers can be consumed outside the scripts import path."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


class NativePathTest(unittest.TestCase):
    def test_path_consumer_can_verify_a_dependency(self):
        source = Path(__file__).resolve().parents[1] / 'scripts/native.py'
        with tempfile.TemporaryDirectory() as directory:
            result = subprocess.run([sys.executable, '-I', '-c', """
import importlib.util
import pathlib
import sys
spec = importlib.util.spec_from_file_location('helper_native', sys.argv[1])
native = importlib.util.module_from_spec(spec)
spec.loader.exec_module(native)
root = pathlib.Path(sys.argv[2])
(root / 'fixture.txt').write_text('synthetic')
assert native.hashes(root) == {'fixture.txt': native.hashlib.sha256(b'synthetic').hexdigest()}
""", str(source), directory], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
