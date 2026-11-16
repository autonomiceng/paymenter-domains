#!/usr/bin/env python3
"""Native receipt and invoice-scoped money runner share one invoice without a deadlock."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import os
from pathlib import Path
import subprocess
import sys
from test_schema_mariadb import ROOT, run


def exercise(command, php_args, env, scratch):
    barriers = scratch/('receipt-locks-'+(env['SCHEMA_PREFIX'] or 'plain'))
    barriers.mkdir(mode=0o700)
    env = dict(env, RECEIPT_BARRIERS=str(barriers))
    script = str(ROOT/'tests/Support/mariadb-receipt-lock-order.php')
    command('receipt-seed', php_args+[script], env=dict(env, RECEIPT_PHASE='seed'), timeout=180)
    # Optional paired evidence uses identical workers against an exported buggy revision.
    # The normal suite needs no Git checkout or historical source.
    revisions = [('candidate', str(ROOT))]
    if os.environ.get('DOMAINS_TEST_RECEIPT_BASE'):
        revisions.insert(0, ('base', os.environ['DOMAINS_TEST_RECEIPT_BASE']))
    proofs = []
    for revision, runtime in revisions:
        for case in ('scoped', 'widened'):
            name = revision+'-'+case
            cell = dict(env, RECEIPT_CASE=case, RECEIPT_NAME=name, RECEIPT_RUNTIME=runtime)
            command('receipt-reset-'+name, php_args+[script], env=dict(cell, RECEIPT_PHASE='reset'))
            with (barriers/(name+'.stdout')).open('wb') as stdout, (barriers/(name+'.stderr')).open('wb') as stderr:
                receipt = subprocess.Popen(php_args+[script], cwd=ROOT, env=dict(cell, RECEIPT_PHASE='receipt'), stdout=stdout, stderr=stderr)
                try:
                    result = command('receipt-runner-'+name, php_args+[script], env=dict(cell, RECEIPT_PHASE='runner'), timeout=90, check=False)
                    if receipt.wait(timeout=45) != 0:
                        raise AssertionError('Native receipt failed; inspect '+str(barriers))
                finally:
                    if receipt.poll() is None:
                        receipt.terminate()
                        try:
                            receipt.wait(timeout=5)
                        except subprocess.TimeoutExpired:
                            receipt.kill(); receipt.wait()
            if (barriers/(name+'-invoice-wait')).read_text() != 'proved':
                raise AssertionError('The runner did not wait on the native invoice')
            proof = json.loads(result.stdout.decode().splitlines()[-1])
            expected = revision != 'base'
            attempts = 2 if case == 'widened' else 1
            if (proof['outcome'] != 'Applied' or proof['transaction_level'] != 0
                    or (not expected and proof['owner_attempts'] <= attempts)
                    or proof['complete'] != expected or (result.returncode == 0) != expected):
                raise AssertionError('Unexpected receipt/runner outcome for '+name+': '+str(proof))
            proofs.append(dict(proof, revision=revision))
    return {'complete': True, 'prefix': env['SCHEMA_PREFIX'], 'cases': proofs, 'real_provider_calls': 0}


if __name__ == '__main__':
    try:
        result = run(exercise=exercise)
        raise SystemExit(77 if result.get('skipped') else (0 if result['complete'] else 1))
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
