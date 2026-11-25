"""Staff Cancel and refund on held paid renewals, clicked in Chromium against the fixture app's HTTP kernel.

A Livewire test calls the mounted action directly, so it cannot see which button the dialog renders. This check
types the reason and clicks the dialog's own button, then reads the saved money from the database. The refund and
the refusals run in separate browser contexts, each against its own database and app storage.
"""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from decimal import Decimal
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
REASON = 'Customer asked to cancel this renewal'
# The browser driver answers this origin itself through the app's kernel; nothing listens on it. Chromium refuses
# other hosts as offline before interception in the suite's network-less namespace, so it is a loopback address.
ORIGIN = 'http://127.0.0.1'
KERNEL = ROOT/'tests/Support/refund-browser-kernel.php'
SCENARIOS = ('refund', 'refusals')


def disposable_app():
    """The runner gives each command its own app copy beside the command's private temporary directory."""
    app = Path(os.environ['PAYMENTER_TEST_FIXTURE']).resolve()
    scratch = Path(os.environ.get('TMPDIR', '/')).resolve()
    assert scratch.parent == app.parent, 'Run through scripts/test.py, which gives this check an app of its own to change'
    return app, scratch


def bridge_environment(app, root, facts, scenario):
    return dict(os.environ, PAYMENTER_TEST_FIXTURE=str(app), REFUND_BROWSER_URL=ORIGIN, REFUND_BROWSER_PHP=str(ROOT/'scripts/php-test'),
                REFUND_BROWSER_DATABASE=str(root/f'{scenario}.sqlite'), REFUND_BROWSER_STORAGE=str(root/f'{scenario}-storage'),
                REFUND_BROWSER_CLOCK=facts['clock'], REFUND_BROWSER_EMAIL=facts['email'], REFUND_BROWSER_SEED=str(root/'seed.json'))


def kernel(env, method, path, headers):
    """One request through the browser driver's kernel, which writes its reply on descriptor 3."""
    wire = json.dumps({'method': method, 'url': ORIGIN+path, 'headers': headers, 'body': ''})
    result = subprocess.run(['sh', '-c', 'exec "$0" "$1" 3>&1 1>&2', env['REFUND_BROWSER_PHP'], str(KERNEL)], input=wire,
                            cwd=env['PAYMENTER_TEST_FIXTURE'], env=env, capture_output=True, text=True, timeout=60)
    assert result.returncode == 0, f'Kernel failed for {method} {path}:\n{result.stderr[-4000:]}'
    return json.loads(result.stdout)


def mismatched_token_status(env):
    """A signed-in staff form post whose CSRF token does not match the session."""
    signed_in = kernel(env, 'GET', '/refund-browser-login', {})
    cookie = '; '.join(line.split(';', 1)[0] for line in signed_in['cookies'])
    return kernel(env, 'POST', '/admin/logout', {'cookie': cookie, 'x-csrf-token': 'mismatched',
                                                 'content-type': 'application/x-www-form-urlencoded'})['status']


def logs(app, root):
    return [path for path in [app/'storage/logs/laravel.log', *(root/f'{s}-storage/logs/laravel.log' for s in SCENARIOS)] if path.is_file()]


def browse(app, root, facts):
    node = shutil.which('node')
    assert node, 'Node.js is required for the browser check'
    env = bridge_environment(app, root, facts, 'refund')
    out = Path(tempfile.mkdtemp(prefix='refund-browser-', dir=root))
    result = subprocess.run([node, '--max-old-space-size=64', str(ROOT/'tests/Support/refund-browser.cjs'), ORIGIN, str(root), str(out)],
                            cwd=ROOT, env=env, capture_output=True, text=True, timeout=240)
    sys.stderr.write(result.stderr)
    assert result.returncode == 0, f'Browser driver failed with exit {result.returncode}'
    report = json.loads(result.stdout.strip().splitlines()[-1])
    if report['errors'] or logs(app, root):
        print(json.dumps(report, indent=1))
        for log in logs(app, root):
            print(log.read_text()[-6000:])
    assert report['errors'] == [], 'The browser steps did not finish'
    assert list(report['scenarios']) == list(SCENARIOS), report['scenarios']
    for name, scenario in report['scenarios'].items():
        # Chromium picks the cookies for the next hop: every cookie the sign-in set reaches /admin, which would otherwise
        # send the browser to sign in, except the one scoped to the sign-in path.
        [hop] = scenario['redirects']
        assert (hop['from'], hop['to'], hop['status']) == ('/refund-browser-login', '/admin', 302), (name, scenario['redirects'])
        assert 'refund-browser-scoped' in hop['set'] and 'refund-browser-scoped' not in hop['sent'], (name, hop)
        assert set(hop['set']) - {'refund-browser-scoped'} <= set(hop['sent']), (name, hop)
        print(f'SAVED {name} ' + json.dumps(scenario['facts']))
    return {step['label']: step for step in report['steps']}, {name: s['facts'] for name, s in report['scenarios'].items()}


def seeded(app, root):
    """Seed every case once, then give each scenario its own copy of the database and its own app storage."""
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
                          env=dict(os.environ, PAYMENTER_TEST_FIXTURE=str(app), REFUND_BROWSER_ROOT=str(root)),
                          capture_output=True, text=True, timeout=120)
    assert seed.returncode == 0, f'Seed failed:\n{seed.stdout}\n{seed.stderr}'
    for scenario in SCENARIOS:
        for area in ('framework/cache/data', 'framework/sessions', 'logs'):
            (root/f'{scenario}-storage'/area).mkdir(parents=True)
        shutil.copyfile(root/'seed.sqlite', root/f'{scenario}.sqlite')
        with sqlite3.connect(root/f'{scenario}.sqlite') as db:
            # The first refund creates the customer's credit; changing that balance later fails, as a database fault would.
            db.execute("CREATE TRIGGER refund_browser_credit_fail BEFORE UPDATE ON credits WHEN OLD.amount > 0 "
                       "BEGIN SELECT RAISE(ABORT, 'synthetic credit failure'); END")
            if scenario == 'refusals':
                db.execute("CREATE TRIGGER refund_browser_insert_fail BEFORE INSERT ON credits "
                           "BEGIN SELECT RAISE(ABORT, 'synthetic credit failure'); END")
    return json.loads((root/'seed.json').read_text())


def main():
    app, scratch = disposable_app()
    root = Path(tempfile.mkdtemp(prefix='refund-browser-', dir=scratch))
    facts = seeded(app, root)
    status = mismatched_token_status(bridge_environment(app, root, facts, 'refund'))
    assert status == 419, f'The kernel refuses a mismatched CSRF token as the web server does: {status}'
    steps, saved = browse(app, root, facts)

    refunded_facts = saved['refund']
    typed = steps['reason-typed']
    assert typed['mounted'] == [{'name': 'refund', 'reason': REASON}], f'The typed reason reaches the dialog: {typed["mounted"]}'
    done = steps['confirmed']
    assert not done['modalOpen'] and done['mounted'] == [], f'Confirming closes the dialog: {done}'
    assert any(n.startswith('Request cancelled') for n in done['notifications']), f'Confirming says the request is cancelled: {done["notifications"]}'
    refunded = refunded_facts['operations']['refund']
    amount = Decimal(facts['refund']['amount'])
    assert refunded['state'] == 'rejected' and refunded['safe_code'] == 'refund_approved', f'The held renewal is closed: {refunded}'
    assert [a for a in refunded['staff_actions'] if a[0] == 'refund'] == [['refund', 'completed', REASON]], \
        f'History keeps one refund with the typed reason: {refunded["staff_actions"]}'
    assert Decimal(refunded['refund']['amount']) == amount and refunded['refund']['to'] == 'account_credit', 'The paid amount is returned'
    assert [(Decimal(a), c) for a, c in refunded_facts['credits']] == [(amount, 'USD')], f'The paid amount returns exactly once: {refunded_facts["credits"]}'
    reloaded = steps['reloaded']['text']
    assert 'Cancelled' in reloaded and 'Credit added' in reloaded, 'The request page shows the cancelled request and its credit'
    assert not {'Cancel and refund', 'Retry refund'} & set(steps['reloaded']['menu']), 'A settled refund offers no second refund or retry'
    duplicate = steps['duplicate']
    assert not duplicate['modalOpen'] and duplicate['mounted'] == [] and duplicate['notificationTitles'] == ['This request changed'], f'A second click from an old dialog is answered: {duplicate}'

    refused = saved['refusals']['operations']
    unknown = steps['unknown-offered']
    assert 'Cancel and refund' not in unknown['menu'], 'An unknown registrar reply is not offered a refund'
    forced = steps['unknown-forced']
    assert not forced['modalOpen'], 'An unknown registrar reply cannot open the refund dialog'
    unsure = refused['unknown']
    assert unsure['state'] == 'uncertain' and not {'refund_authorization', 'refund'} & set(unsure['funding_keys']), 'Nothing is refunded for an unknown reply'
    warning = 'Cancelled, refund not finished'
    assert any(n.startswith(warning) for n in steps['failed']['notifications']), f'A failed refund says so: {steps["failed"]["notifications"]}'
    assert steps['failed']['notificationDurations'] == ['persistent'], 'The failure notice has no auto-close duration'
    assert any(n.startswith(warning) for n in steps['failed-later']['notifications']), 'The failure stays on screen'
    failing = refused['failing']
    assert failing['state'] == 'rejected' and 'refund_authorization' in failing['funding_keys'] and 'refund' not in failing['funding_keys'], \
        f'A failed refund keeps the decision and stays retryable: {failing}'
    assert saved['refusals']['credits'] == [], f'A refused or failed refund adds no credit: {saved["refusals"]["credits"]}'
    print('Staff Cancel and refund closes a held paid renewal once, from the dialog button, in Chromium, and refuses or reports the rest.')


if __name__ == '__main__':
    main()
