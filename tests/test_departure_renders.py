"""Build the departure render fixtures and verify that they produce their artifacts."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
RENDERS = {
    'tests/Support/departure-alerts-render.php': ['staff-departed-alerts.html'],
    'tests/Support/departure-departed-render.php': ['staff-departed.html', 'staff-departed-history.html'],
    'tests/Support/departure-email-render.php': ['email-departed.html'],
}


class DepartureRenders(unittest.TestCase):
    def test_departure_fixtures_build_and_render(self):
        fixture = Path(os.environ['PAYMENTER_TEST_FIXTURE'])
        for path, artifacts in RENDERS.items():
            with self.subTest(fixture=path), tempfile.TemporaryDirectory(
                prefix='domains-departure-render-', dir=fixture.parent
            ) as output:
                result = subprocess.run(
                    [str(ROOT/'scripts/php-test'), path, output], cwd=ROOT,
                    capture_output=True, text=True, timeout=60,
                )
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                for name in artifacts:
                    artifact = Path(output)/name
                    self.assertTrue(artifact.is_file(), f'{path}: missing render {name}')
                    self.assertGreater(artifact.stat().st_size, 0, f'{path}: empty render {name}')


if __name__ == '__main__':
    unittest.main()
