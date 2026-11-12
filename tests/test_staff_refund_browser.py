"""Staff Cancel and refund on held paid renewals, clicked in Chromium against the served fixture app.

A Livewire test calls the mounted action directly, so it cannot see which button the dialog renders. This check
types the reason and clicks the dialog's own button, then reads the saved money from the database.
"""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from decimal import Decimal
import json
import os
from pathlib import Path
import selectors
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import installed_fixture

ROOT = Path(__file__).resolve().parents[1]
REASON = 'Customer asked to cancel this renewal'


def free_port():
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        return probe.getsockname()[1]


def started(server, timeout):
    """Wait for the built-in server's start line, without sleeping."""
    with selectors.DefaultSelector() as selector:
        selector.register(server.stderr, selectors.EVENT_READ)
        lines = []
        while selector.select(timeout):
            line = server.stderr.readline()
            if not line:
                break
            lines.append(line)
            if 'started' in line:
                return
        raise AssertionError('The fixture server did not start: ' + ''.join(lines))


def release_fixture_cache(app, *, copied=True):
    """Do not retain copied fixture assets in file cache alongside Chromium."""
    if not hasattr(os, 'posix_fadvise'):
        return
    for directory, _, files in os.walk(app, followlinks=False):
        for name in files:
            path = Path(directory) / name
            if path.is_symlink():
                continue
            with path.open('rb') as handle:
                if copied:
                    os.fsync(handle.fileno())
                os.posix_fadvise(handle.fileno(), 0, 0, os.POSIX_FADV_DONTNEED)


def browse(app, root, facts, scenario):
    node = shutil.which('node')
    assert node, 'Node.js is required for the browser check'
    php = str(ROOT/'scripts/php-test')
    port = free_port()
    base = f'http://127.0.0.1:{port}'
    env = dict(os.environ, PAYMENTER_TEST_FIXTURE=str(app), REFUND_BROWSER_URL=base, REFUND_BROWSER_DATABASE=str(root/'seed.sqlite'),
               REFUND_BROWSER_CASE=scenario, REFUND_BROWSER_CLOCK=facts['clock'], REFUND_BROWSER_EMAIL=facts['email'], REFUND_BROWSER_SEED=str(root/'seed.json'))
    out = Path(tempfile.mkdtemp(prefix='refund-browser-', dir=root))
    server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', str(app/'public'), str(ROOT/'tests/Support/refund-browser-router.php')],
                              cwd=app, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
    try:
        started(server, 30)
        result = subprocess.run([node, '--max-old-space-size=64', str(ROOT/'tests/Support/refund-browser.cjs'), base, str(root/'seed.json'), str(out)],
                                cwd=ROOT, env=env, capture_output=True, text=True, timeout=240)
    finally:
        server.terminate()
        server.wait(timeout=30)
    sys.stderr.write(result.stderr)
    log = app/'storage/logs/laravel.log'
    assert result.returncode == 0, f'Browser driver failed with exit {result.returncode}'
    report = json.loads(result.stdout.strip().splitlines()[-1])
    if report['errors'] or log.is_file():
        print(json.dumps(report, indent=1))
        if log.is_file():
            print(log.read_text()[-6000:])
    assert report['errors'] == [], 'The browser steps did not finish'
    print('SAVED ' + json.dumps(report['facts']))
    return {step['label']: step for step in report['steps']}, report['facts']


def main(scenario='refund'):
    fixture = Path(os.environ['PAYMENTER_TEST_FIXTURE'])
    with installed_fixture.application(fixture, 'refund-browser') as (root, app):
        installed = app/'extensions/Servers/Domains'
        if installed.is_symlink():
            installed.unlink()
        elif installed.exists():
            shutil.rmtree(installed)
        installed.parent.mkdir(parents=True, exist_ok=True)
        installed.symlink_to(ROOT/'Domains', target_is_directory=True)
        for path in (app/'bootstrap/cache').glob('*.php'):
            path.unlink()
        seed = subprocess.run([str(ROOT/'scripts/php-test'), 'tests/Support/refund-browser-seed.php'], cwd=ROOT,
                              env=dict(os.environ, REFUND_BROWSER_ROOT=str(root), REFUND_BROWSER_CASE=scenario), capture_output=True, text=True, timeout=120)
        assert seed.returncode == 0, f'Seed failed:\n{seed.stdout}\n{seed.stderr}'
        facts = json.loads((root/'seed.json').read_text())
        database = root/'seed.sqlite'
        with sqlite3.connect(database) as db:
            # The first refund creates the customer's credit; changing that balance later fails, as a database fault would.
            db.execute("CREATE TRIGGER refund_browser_credit_fail BEFORE UPDATE ON credits WHEN OLD.amount > 0 "
                       "BEGIN SELECT RAISE(ABORT, 'synthetic credit failure'); END")
            if scenario == 'refusals':
                db.execute("CREATE TRIGGER refund_browser_insert_fail BEFORE INSERT ON credits "
                           "BEGIN SELECT RAISE(ABORT, 'synthetic credit failure'); END")
        release_fixture_cache(app)
        steps, saved = browse(app, root, facts, scenario)
        operation = saved['operations']

        if scenario == 'refund':
            typed = steps['reason-typed']
            assert typed['mounted'] == [{'name': 'refund', 'reason': REASON}], f'The typed reason reaches the dialog: {typed["mounted"]}'
            done = steps['confirmed']
            assert not done['modalOpen'] and done['mounted'] == [], f'Confirming closes the dialog: {done}'
            assert any(n.startswith('Request cancelled') for n in done['notifications']), f'Confirming says the request is cancelled: {done["notifications"]}'
            refunded = operation['refund']
            amount = Decimal(facts['refund']['amount'])
            assert refunded['state'] == 'rejected' and refunded['safe_code'] == 'refund_approved', f'The held renewal is closed: {refunded}'
            assert [a for a in refunded['staff_actions'] if a[0] == 'refund'] == [['refund', 'completed', REASON]], \
                f'History keeps one refund with the typed reason: {refunded["staff_actions"]}'
            assert Decimal(refunded['refund']['amount']) == amount and refunded['refund']['to'] == 'account_credit', 'The paid amount is returned'
            assert [(Decimal(a), c) for a, c in saved['credits']] == [(amount, 'USD')], f'The paid amount returns exactly once: {saved["credits"]}'
            reloaded = steps['reloaded']['text']
            assert 'Cancelled' in reloaded and 'Credit added' in reloaded, 'The request page shows the cancelled request and its credit'
            assert 'Cancel and refund' not in steps['reloaded']['menu'], 'A closed request offers no second refund'
            duplicate = steps['duplicate']
            assert not duplicate['modalOpen'] and duplicate['mounted'] == [] and duplicate['notificationTitles'] == ['This request changed'], f'A second click from an old dialog is answered: {duplicate}'

        else:
            unknown = steps['unknown-offered']
            assert 'Cancel and refund' not in unknown['menu'], 'An unknown registrar reply is not offered a refund'
            forced = steps['unknown-forced']
            assert not forced['modalOpen'], 'An unknown registrar reply cannot open the refund dialog'
            unsure = operation['unknown']
            assert unsure['state'] == 'uncertain' and not {'refund_authorization', 'refund'} & set(unsure['funding_keys']), 'Nothing is refunded for an unknown reply'

            warning = 'Cancelled, refund not finished'
            assert any(n.startswith(warning) for n in steps['failed']['notifications']), f'A failed refund says so: {steps["failed"]["notifications"]}'
            assert steps['failed']['notificationDurations'] == ['persistent'], 'The failure notice has no auto-close duration'
            assert any(n.startswith(warning) for n in steps['failed-later']['notifications']), 'The failure stays on screen'
            failing = operation['failing']
            assert failing['state'] == 'rejected' and 'refund_authorization' in failing['funding_keys'] and 'refund' not in failing['funding_keys'], \
                f'A failed refund keeps the decision and stays retryable: {failing}'
        print('Staff Cancel and refund closes a held paid renewal once, from the dialog button, in Chromium.')


if __name__ == '__main__':
    main()
