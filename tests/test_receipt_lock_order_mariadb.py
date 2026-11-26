#!/usr/bin/env python3
"""A native receipt on a cancelled renewal takes no Domains locks inside core's payment transaction, so another
customer's full decision and the same customer's checkout both finish beside it; the payment is always recorded."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
from pathlib import Path
import subprocess
import sys
from test_schema_mariadb import ROOT, run


def proof(text):
    return json.loads(next(line for line in reversed(text.splitlines()) if line.startswith('{')))


def exercise(command, php_args, env, scratch):
    barriers = scratch/('receipt-locks-'+(env['SCHEMA_PREFIX'] or 'plain'))
    barriers.mkdir(mode=0o700)
    env = dict(env, RECEIPT_BARRIERS=str(barriers))
    script = str(ROOT/'tests/Support/mariadb-receipt-lock-order.php')
    command('receipt-seed', php_args+[script], env=dict(env, RECEIPT_PHASE='seed'), timeout=180)
    proofs = []
    for case in ('other', 'checkout'):
        cell = dict(env, RECEIPT_CASE=case, RECEIPT_NAME=case)
        with (barriers/(case+'.stdout')).open('wb') as stdout, (barriers/(case+'.stderr')).open('wb') as stderr:
            receipt = subprocess.Popen(php_args+[script], cwd=ROOT, env=dict(cell, RECEIPT_PHASE='receipt'), stdout=stdout, stderr=stderr)
            try:
                result = command('receipt-runner-'+case, php_args+[script], env=dict(cell, RECEIPT_PHASE='runner'), timeout=90, check=False)
                receipt_code = receipt.wait(timeout=60)
            finally:
                if receipt.poll() is None:
                    receipt.terminate()
                    try:
                        receipt.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        receipt.kill(); receipt.wait()
        native = proof((barriers/(case+'.stdout')).read_text())
        other = proof(result.stdout.decode())
        if receipt_code != 0 or not native['complete'] or result.returncode != 0 or not other['complete']:
            raise AssertionError('Unexpected receipt outcome for '+case+': '+str((native, other))+'; inspect '+str(barriers))
        proofs.append(dict(native, other=other))
    return {'complete': True, 'prefix': env['SCHEMA_PREFIX'], 'cases': proofs, 'real_provider_calls': 0}


if __name__ == '__main__':
    try:
        result = run(exercise=exercise)
        raise SystemExit(77 if result.get('skipped') else (0 if result['complete'] else 1))
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
