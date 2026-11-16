#!/usr/bin/env python3
"""Opt-in paired native User deletion/checkout on the existing socket-only MariaDB harness."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import os
from pathlib import Path
import subprocess
import sys
from test_schema_mariadb import ROOT, run


def exercise(command, php_args, env, scratch):
    barriers = scratch/('user-deletion-'+(env['SCHEMA_PREFIX'] or 'plain'))
    barriers.mkdir(mode=0o700)
    env = dict(env, USERDEL_BARRIERS=str(barriers))
    script = str(ROOT/'tests/Support/mariadb-user-deletion.php')
    command('user-deletion-seed', php_args+[script], env=dict(env, USERDEL_PHASE='seed'), timeout=180)
    for case in ('intent', 'checkout', 'snapshot'):
        child_env = dict(env, USERDEL_CASE=case, USERDEL_PHASE='checkout')
        with (barriers/(case+'.stdout')).open('wb') as stdout, (barriers/(case+'.stderr')).open('wb') as stderr:
            child = subprocess.Popen(php_args+[script], cwd=ROOT, env=child_env, stdout=stdout, stderr=stderr)
            try:
                command('user-deletion-'+case, php_args+[script], env=dict(env, USERDEL_CASE=case, USERDEL_PHASE='delete'), timeout=90)
                if child.wait(timeout=45) != 0:
                    raise AssertionError('Checkout failed; inspect private logs: '+str(barriers))
                proof = json.loads((barriers/(case+'.stdout')).read_text().splitlines()[-1])
                if not proof['complete'] or proof['case'] != case:
                    raise AssertionError('Wrong checkout proof')
            finally:
                if child.poll() is None:
                    child.terminate()
                    try:
                        child.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        child.kill(); child.wait()
    if (barriers/'checkout-lock-wait').read_text() != 'proved':
        raise AssertionError('Real user row lock wait was not observed')
    refunds = refund_contention(command, php_args, env, scratch) if env['SCHEMA_PREFIX'] == '' else None
    return {'complete': True, 'prefix': env['SCHEMA_PREFIX'], 'intent_first': True,
            'checkout_first_lock_wait': True, 'older_repeatable_read': True, 'refund_contention': refunds, 'real_provider_calls': 0}


def refund_contention(command, php_args, env, scratch):
    """Refund credit after real deadlocks and lock wait timeouts, on this scenario's database."""
    barriers = scratch/'refund-contention'
    barriers.mkdir(mode=0o700)
    env = dict(env, REFUND_BARRIERS=str(barriers))
    script = str(ROOT/'tests/Support/mariadb-refund-contention.php')
    with (barriers/'holder.stdout').open('wb') as stdout, (barriers/'holder.stderr').open('wb') as stderr:
        holder = subprocess.Popen(php_args+[script], cwd=ROOT, env=dict(env, REFUND_ROLE='holder'), stdout=stdout, stderr=stderr)
        try:
            result = command('refund-contention', php_args+[script], env=dict(env, REFUND_ROLE='victim'), timeout=120)
            if holder.wait(timeout=45) != 0:
                raise AssertionError('Refund contention holder failed; inspect private logs: '+str(barriers))
        finally:
            if holder.poll() is None:
                holder.terminate()
                try:
                    holder.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    holder.kill(); holder.wait()
    proof = json.loads(result.stdout.decode().splitlines()[-1])
    if not proof['complete'] or proof['cases'] != ['retry', 'deferred', 'timeout']:
        raise AssertionError('Wrong refund contention proof')
    return proof['cases']


if __name__ == '__main__':
    try:
        result = run(exercise=exercise)
        raise SystemExit(77 if result.get('skipped') else (0 if result['complete'] else 1))
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
