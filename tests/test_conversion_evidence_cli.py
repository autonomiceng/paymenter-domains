#!/usr/bin/env python3
"""Exercise the real collector CLI using socket-free PHP transport functions."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import base64
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
fixture = Path(os.environ['PAYMENTER_TEST_FIXTURE'])
php = str(ROOT/'scripts/php-test')
helper = str(ROOT/'tests/Support/evidence-cli.php')
@contextmanager
def parked_runtime():
    runtime = fixture/'extensions/Servers/Domains'
    parked = fixture/'collector-parked-Domains'
    assert not parked.exists() and not parked.is_symlink()
    present = runtime.exists() or runtime.is_symlink()
    if present:
        runtime.rename(parked)
    try:
        yield
    finally:
        if present:
            parked.rename(runtime)

with tempfile.TemporaryDirectory(prefix='conversion-evidence-cli-') as directory, parked_runtime():
    scratch = Path(directory)
    seed = subprocess.run([php, helper, directory], capture_output=True, timeout=120)
    assert seed.returncode == 0, seed.stdout + seed.stderr
    env = dict(os.environ, APP_ENV='testing', APP_KEY='base64:'+base64.b64encode(b't'*32).decode(),
               APP_URL='http://domains-tests.invalid', DB_CONNECTION='sqlite', CACHE_STORE='database',
               SESSION_DRIVER='database', MAIL_MAILER='array', TELEMETRY_ENABLED='false',
               EVIDENCE_WIRE=str(scratch/'wire.jsonl'))
    token = b'synthetic-token'
    def call(label, database='test', arguments=(), success=False, app=None, staff='1', message='Collection refused.'):
        out = scratch/(label+'.json')
        wire = scratch/'wire.jsonl'
        wire.unlink(missing_ok=True)
        db = scratch/(database+'.sqlite')
        before = hashlib.sha256(db.read_bytes()).hexdigest()
        result = subprocess.run([php, '-d', 'auto_prepend_file='+helper,
                                 str(ROOT/'scripts/collect-conversion-evidence.php'), '--app', str(app or fixture),
                                 '--out', str(out), '--accepted-by', staff, '--reason', 'conversion_review', *arguments],
                                env=dict(env, DB_DATABASE=str(db)), capture_output=True, timeout=120)
        assert result.stdout == b'', (label, result.stdout)
        assert result.returncode == (0 if success else 1), (label, result.stderr)
        assert token not in result.stdout + result.stderr + (out.read_bytes() if out.exists() else b''), label
        assert hashlib.sha256(db.read_bytes()).hexdigest() == before, label
        if success:
            assert out.stat().st_mode & 0o777 == 0o600
            assert json.loads(result.stderr)['collected'] == 1
            calls = [json.loads(line) for line in wire.read_text().splitlines()]
            assert calls == [[('https://reseller.enom.com/interface.asp' if database == 'live' else 'https://resellertest.enom.com/interface.asp'), 'GetDomainInfo', 'purchased-placeholder']]
        else:
            assert message.encode() in result.stderr, (label, result.stderr)
            assert not out.exists() and not wire.exists(), label
    # Read the synthetic staff ID without assuming native sequence order.
    metadata = json.loads((scratch/'staff.json').read_text())
    original_call = call
    def call(label, **kwargs):
        kwargs.setdefault('staff', str(metadata['staff']))
        return original_call(label, **kwargs)
    call('test-success', success=True, arguments=['--domain', '21'])
    call('live-success', database='live', success=True, arguments=['--live', '--domain', '21'])
    call('missing-live', database='live', arguments=['--domain', '21'])
    call('missing-ids', database='live', arguments=['--live'])
    call('bad-staff', staff='999999')
    call('bad-id-argument', staff='bad')
    call('unknown-option', arguments=['--domains', '21'])
    call('noncanonical', app=str(fixture)+'/../'+fixture.name)
    call('empty-scope', arguments=['--domain', '23'], message='Account evidence incomplete; no file published.')
    for name in ['Enom', 'Domains']:
        unparked = fixture/('extensions/Servers/'+name)
        unparked.mkdir()
        try:
            call('unparked-'+name)
        finally:
            unparked.rmdir()
print('Evidence CLI: real argument/live wiring, private outputs and read-only bootstrap passed')
