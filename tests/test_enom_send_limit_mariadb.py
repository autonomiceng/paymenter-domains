#!/usr/bin/env python3
"""Socket-only MariaDB: install drops retired TEST tables; concurrent sends admit exactly the daily or per-minute limit."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import subprocess
import sys
import time
from test_schema_mariadb import ROOT, run


def exercise(command, php_args, env, scratch):
    script = str(ROOT/'tests/Support/mariadb-enom-send-limit.php')
    def phase(name):
        return command('send-limit-'+name, php_args+[script], env=dict(env, LIMIT_PHASE=name), timeout=180)
    def race(day, minute, uid='Synthetic-UID'):
        limits = dict(LIMIT_PHASE='admit', LIMIT_DAY=str(day), LIMIT_MINUTE=str(minute), LIMIT_UID=uid,
                      LIMIT_START=str(time.time()+10))
        children = [subprocess.Popen(php_args+[script], env=dict(env, **limits),
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE) for _ in range(6)]
        outcomes = []
        try:
            for child in children:
                out, err = child.communicate(timeout=60)
                if child.returncode:
                    raise AssertionError('Send limit racer failed: '+err.decode())
                outcomes.append(json.loads(out)['outcome'])
        finally:
            for child in children:
                if child.poll() is None:
                    child.kill(); child.wait(timeout=10)
        if outcomes.count('admitted') != 3 or outcomes.count('refused') != 3:
            raise AssertionError('Send limit over- or under-admitted: '+str(outcomes))
    phase('seed')
    race(3, 100)  # first use: the login has no row yet
    race(6, 100)  # the row exists: lock waits, never false refusals
    race(100, 3, 'Minute-UID')  # the per-minute limit
    return json.loads(phase('verify').stdout)


if __name__ == '__main__':
    try:
        proof = run(exercise=exercise)
        if proof.get('skipped'):
            raise RuntimeError('Send limit MariaDB acceptance may not skip')
        raise SystemExit(0 if proof['complete'] else 1)
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
