#!/usr/bin/env python3
"""Native checkout against a full money decision and contact recovery: customer first, then invoices, no deadlock."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import subprocess
import sys
from test_schema_mariadb import ROOT, run


def proof(text):
    return json.loads(next(line for line in reversed(text.splitlines()) if line.startswith('{')))


def exercise(command, php_args, env, scratch):
    barriers = scratch/('checkout-locks-'+(env['SCHEMA_PREFIX'] or 'plain'))
    barriers.mkdir(mode=0o700)
    env = dict(env, CHECKOUT_BARRIERS=str(barriers))
    script = str(ROOT/'tests/Support/mariadb-checkout-lock-order.php')
    command('checkout-lock-seed', php_args+[script], env=dict(env, CHECKOUT_PHASE='seed'), timeout=180)
    cases = []
    for case in ('decision', 'contact'):
        cell = dict(env, CHECKOUT_CASE=case)
        with (barriers/(case+'.stdout')).open('wb') as stdout, (barriers/(case+'.stderr')).open('wb') as stderr:
            checkout = subprocess.Popen(php_args+[script], cwd=ROOT, env=dict(cell, CHECKOUT_PHASE='checkout'), stdout=stdout, stderr=stderr)
            try:
                result = command('checkout-lock-'+case, php_args+[script], env=dict(cell, CHECKOUT_PHASE='writer'), timeout=90, check=False)
                if checkout.wait(timeout=45) != 0:
                    raise AssertionError('Checkout failed; inspect private logs: '+str(barriers))
            finally:
                if checkout.poll() is None:
                    checkout.terminate()
                    try:
                        checkout.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        checkout.kill(); checkout.wait()
        writer = proof(result.stdout.decode())
        if result.returncode != 0 or not writer['complete'] or writer['error'] is not None or not writer['customer_first']:
            raise AssertionError('Unexpected writer outcome for '+case+': '+str(writer))
        if not proof((barriers/(case+'.stdout')).read_text())['complete']:
            raise AssertionError('Wrong checkout proof for '+case)
        if (barriers/(case+'-customer-wait')).read_text() != 'proved':
            raise AssertionError('The '+case+' writer did not wait on the checkout customer row')
        cases.append(writer)
    return {'complete': True, 'prefix': env['SCHEMA_PREFIX'], 'cases': cases, 'real_provider_calls': 0}


if __name__ == '__main__':
    try:
        result = run(exercise=exercise)
        raise SystemExit(77 if result.get('skipped') else (0 if result['complete'] else 1))
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
