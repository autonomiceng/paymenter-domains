# MIT, Copyright (c) 2026 Autonomic Engineering. The retired converter entry point names the bridge route.
from pathlib import Path
import os
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = ('Conversion refused: This package has no converter. Run the one-time conversion with the converter '
          'from Domains bridge 0.1.0 at 891124421645, run its checked catalogue conversion, then install this package.\n')


class ConversionRefusalTests(unittest.TestCase):
    def test_entry_point_refuses_with_bridge_steps_and_touches_nothing(self):
        with tempfile.TemporaryDirectory(prefix='conversion-refusal-') as directory:
            app = Path(directory)/'app'; app.mkdir()
            for arguments in [[], ['--app', str(app), '--source', str(app), '--source-revision', '0'*40,
                                   '--evidence', str(app/'evidence.json'), '--stage-schema'],
                              ['--app', str(app), '--apply', '--backup-marker', str(app/'marker.json')]]:
                with self.subTest(arguments=arguments):
                    result = subprocess.run([str(ROOT/'scripts/php-test'), str(ROOT/'scripts/convert-enom-05.php'), *arguments],
                                            cwd=ROOT, env=dict(os.environ), capture_output=True, text=True, timeout=30)
                    self.assertEqual(result.returncode, 1)
                    self.assertEqual(result.stdout, '')
                    self.assertEqual(result.stderr, BRIDGE)
                    self.assertEqual(list(app.iterdir()), [])


if __name__ == '__main__':
    unittest.main()
