"""A damaged resource receipt fails its command without hiding later failures."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
spec = importlib.util.spec_from_file_location('receipt_runner', ROOT/'scripts/test.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class ReceiptTests(unittest.TestCase):
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
