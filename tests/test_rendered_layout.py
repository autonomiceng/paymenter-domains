"""Render the staff lists and the client domain page with real Blade, then measure them in Chromium."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
# Each writes its pages into the directory: the monitoring list (by R14_RENDER_DIR), the domain page and the Domains list.
RENDERS = ['tests/test_installed_demo_c_state_watch-test.php', 'tests/domain-page-render.php', 'tests/staff-list-render.php']
CHECK = 'scripts/check-name-and-heading.cjs'


def main():
    node = shutil.which('node')
    assert node, 'Node.js is required for the rendered layout check'
    assert os.environ.get('PAYMENTER_TEST_FIXTURE'), 'Run through scripts/test.py with PAYMENTER_TEST_APP'
    with tempfile.TemporaryDirectory(prefix='domains-layout-') as output:
        env = dict(os.environ, R14_RENDER_DIR=output)
        for path in RENDERS:
            result = subprocess.run([str(ROOT/'scripts/php-test'), path, output], cwd=ROOT, env=env,
                                    capture_output=True, text=True, timeout=120)
            assert result.returncode == 0, f'{path} did not render:\n{result.stdout}\n{result.stderr}'
        for page in ['staff-domains-list.html', 'r14-watchlist-paused.html', 'r14-watchlist-retired.html',
                     'domain-page-invoice-due-dark.html', 'domain-page-invoice-due-light.html']:
            assert (Path(output)/page).is_file(), f'Missing render: {page}'
        result = subprocess.run([node, str(ROOT/CHECK), output], cwd=ROOT, env=env,
                                capture_output=True, text=True, timeout=150)
        sys.stdout.write(result.stdout)
        sys.stderr.write(result.stderr)
        if "Cannot find module 'playwright'" in result.stderr:
            sys.exit('The ui suite needs an installed Playwright: set NODE_PATH to its node_modules and CHROME_BIN to Chromium.')
        lines = result.stdout.strip().splitlines()
        receipt = json.loads(lines[-1]) if lines else {}
        assert result.returncode == 0 and receipt.get('complete') is True and receipt.get('failures') == [], \
            f'{CHECK} failed with exit {result.returncode}'
        # Every expected item was found and measured: a check that finds nothing is not a pass.
        assert receipt.get('measured', 0) >= 56, f'Too few rendered items measured: {receipt.get("measured")}'
    print('Rendered staff lists and domain heading hold their layout in Chromium.')


if __name__ == '__main__':
    main()
