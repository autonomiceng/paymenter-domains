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
CHECK = 'scripts/check-name-and-heading.cjs'
# Per scope: the render files, each writing its pages into the directory, the pages, and the least measured items. lists:
# the monitoring list (by R14_RENDER_DIR), the domain page and the Domains list. managed: the DNS monitoring setup list,
# alone in tests/test_rendered_managed_dns.py so each run keeps memory headroom.
SCOPES = {
    'lists': (['tests/test_installed_demo_c_state_watch-test.php', 'tests/domain-page-render.php', 'tests/staff-list-render.php'],
              ['staff-domains-list.html', 'r14-watchlist-paused.html', 'r14-watchlist-retired.html',
               'domain-page-invoice-due-dark.html', 'domain-page-invoice-due-light.html'], 56),
    'staff': (['tests/staff-list-render.php'], ['staff-domains-list.html'], 24),
    'watch': (['tests/test_installed_demo_c_state_watch-test.php'], ['r14-watchlist-paused.html', 'r14-watchlist-retired.html'], 24),
    'heading': (['tests/domain-page-render.php'], ['domain-page-invoice-due-dark.html', 'domain-page-invoice-due-light.html'], 8),
    'managed': (['tests/managed-dns-render.php'], ['staff-managed-dns.html'], 6),
}


def main(scope='lists'):
    renders, pages, least = SCOPES[scope]
    node = shutil.which('node')
    assert node, 'Node.js is required for the rendered layout check'
    assert os.environ.get('PAYMENTER_TEST_FIXTURE'), 'Run through scripts/test.py with PAYMENTER_TEST_APP'
    with tempfile.TemporaryDirectory(prefix='domains-layout-') as output:
        env = dict(os.environ, R14_RENDER_DIR=output)
        for path in renders:
            result = subprocess.run([str(ROOT/'scripts/php-test'), path, output], cwd=ROOT, env=env,
                                    capture_output=True, text=True, timeout=120)
            assert result.returncode == 0, f'{path} did not render:\n{result.stdout}\n{result.stderr}'
        for page in pages:
            assert (Path(output)/page).is_file(), f'Missing render: {page}'
        result = subprocess.run([node, str(ROOT/CHECK), output, scope], cwd=ROOT, env=env,
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
        assert receipt.get('scopes') == [scope], f'The check ran {receipt.get("scopes")}, not {scope}'
        assert receipt.get('measured', 0) >= least, f'Too few rendered items measured: {receipt.get("measured")}'
    print(f'Rendered staff pages ({scope}) hold their layout in Chromium.')


if __name__ == '__main__':
    main('staff')
