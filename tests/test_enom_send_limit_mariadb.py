#!/usr/bin/env python3
"""Socket-only MariaDB: install drops retired TEST tables; concurrent sends admit exactly the daily or per-minute limit."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import os
import select
import subprocess
import sys
import time
from test_schema_mariadb import ROOT, run

WORKERS = 6


def arrivals(ready, children, seconds):
    """The worker numbers in the order each reached the barrier; fails when one exits first or SECONDS of monotonic
    time pass before all have."""
    deadline, seen = time.monotonic()+seconds, b''
    while len(seen) < len(children):
        if any(child.poll() is not None for child in children):
            raise AssertionError('A send limit racer exited before the barrier: '+str([child.returncode for child in children]))
        left = deadline-time.monotonic()
        if left <= 0:
            raise AssertionError(f'Only {len(seen)} of {len(children)} send limit racers reached the barrier')
        if select.select([ready], [], [], min(left, 0.5))[0]:
            seen += os.read(ready, len(children))
    return seen.decode()


def judged(reports):
    """One round's proof from its racers' reports: exactly three admitted and three refused over the limit, and at
    least one InnoDB row lock wait after the last racer's reading before the barrier. A database error or a round
    whose racers never waited on the account row fails."""
    outcomes = [report['outcome'] for report in reports]
    if outcomes.count('admitted') != 3 or outcomes.count('over_limit') != 3:
        raise AssertionError('Send limit must admit 3 and refuse 3 over the limit: '+str(outcomes))
    befores, afters = zip(*(report['row_lock_waits'] for report in reports))
    waits = max(afters)-max(befores)
    if waits < 1:
        raise AssertionError('No racer waited on the account row lock, so the round was not concurrent')
    return {'admitted': 3, 'refused': 3, 'row_lock_waits': waits}


def exercise(command, php_args, env, scratch):
    script = str(ROOT/'tests/Support/mariadb-enom-send-limit.php')
    races = []
    def phase(name):
        return command('send-limit-'+name, php_args+[script], env=dict(env, LIMIT_PHASE=name), timeout=180)
    def race(day, minute, uid='Synthetic-UID'):
        ready, ready_end = os.pipe()
        release_end, release = os.pipe()
        limits = dict(LIMIT_PHASE='admit', LIMIT_DAY=str(day), LIMIT_MINUTE=str(minute), LIMIT_UID=uid,
                      LIMIT_READY_FD=str(ready_end), LIMIT_RELEASE_FD=str(release_end))
        children, reports = [], []
        try:
            for worker in range(WORKERS):
                children.append(subprocess.Popen(php_args+[script], env=dict(env, **limits, LIMIT_WORKER=str(worker)),
                                                 pass_fds=(ready_end, release_end), stdout=subprocess.PIPE, stderr=subprocess.PIPE))
            os.close(ready_end); os.close(release_end)
            ready_end = release_end = None
            order = arrivals(ready, children, 60)
            os.close(release)  # releases every waiting worker at once
            release = None
            for child in children:
                out, err = child.communicate(timeout=60)
                if child.returncode:
                    raise AssertionError('Send limit racer failed: '+err.decode())
                reports.append(json.loads(out))
        finally:
            for pipe in (ready, ready_end, release_end, release):
                if pipe is not None:
                    os.close(pipe)
            for child in children:
                if child.poll() is None:
                    child.kill(); child.wait(timeout=10)
        if sorted(order) != [str(worker) for worker in range(WORKERS)]:
            raise AssertionError('Each racer must reach the barrier once: '+order)
        races.append({'arrivals': order, **judged(reports)})
    phase('seed')
    race(3, 100)  # first use: the login has no row yet
    race(6, 100)  # the row exists: lock waits, never false refusals
    race(100, 3, 'Minute-UID')  # the per-minute limit
    return dict(json.loads(phase('verify').stdout), races=races)


if __name__ == '__main__':
    try:
        proof = run(exercise=exercise)
        if proof.get('skipped'):
            raise RuntimeError('Send limit MariaDB acceptance may not skip')
        raise SystemExit(0 if proof['complete'] else 1)
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
