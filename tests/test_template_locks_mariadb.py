#!/usr/bin/env python3
"""Socket-only MariaDB: DNS template turn off and delete wait on the template lock and never act on a late assignment."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import subprocess
import sys
from test_schema_mariadb import ROOT, run

# late: a staff assignment commits while the write waits, after the write's repeatable-read view began.
# timeout and deadlock: the waiting write fails and rolls back with the template still on.
CASES = [(case, action) for case in ('late', 'timeout', 'deadlock') for action in ('disable', 'delete')]


def exercise(command, php_args, env, scratch):
    barriers = scratch/('template-locks-'+(env['SCHEMA_PREFIX'] or 'plain'))
    barriers.mkdir(mode=0o700)
    env = dict(env, TPL_BARRIERS=str(barriers))
    script = str(ROOT/'tests/Support/mariadb-template-locks.php')
    command('template-locks-seed', php_args+[script], env=dict(env, TPL_PHASE='seed'), timeout=180)
    proofs = []
    for case, action in CASES:
        name = case+'-'+action
        cell = dict(env, TPL_CASE=case, TPL_ACTION=action)
        with (barriers/(name+'.stdout')).open('wb') as stdout, (barriers/(name+'.stderr')).open('wb') as stderr:
            holder = subprocess.Popen(php_args+[script], cwd=ROOT, env=dict(cell, TPL_PHASE='hold'), stdout=stdout, stderr=stderr)
            try:
                result = command('template-locks-'+name, php_args+[script], env=dict(cell, TPL_PHASE='mutate'), timeout=90)
                if holder.wait(timeout=45) != 0:
                    raise AssertionError('Template lock holder failed; inspect private logs: '+str(barriers))
            finally:
                if holder.poll() is None:
                    holder.terminate()
                    try:
                        holder.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        holder.kill(); holder.wait()
        proof = json.loads(result.stdout.decode().splitlines()[-1])
        if not proof['complete'] or (proof['case'], proof['action']) != (case, action):
            raise AssertionError('Wrong template lock proof for '+name)
        if (barriers/(name+'-lock-wait')).read_text() != 'proved':
            raise AssertionError('Real template row lock wait was not observed for '+name)
        proofs.append(proof)
    return {'complete': True, 'prefix': env['SCHEMA_PREFIX'], 'cases': proofs, 'real_provider_calls': 0}


if __name__ == '__main__':
    try:
        result = run(exercise=exercise)
        raise SystemExit(77 if result.get('skipped') else (0 if result['complete'] else 1))
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
