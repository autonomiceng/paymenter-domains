#!/usr/bin/env python3
"""Installed socket-only shared scheduler claim race, with two independent PHP connections."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import subprocess
import sys
import time
from test_schema_mariadb import ROOT, run


def exercise(command, php_args, env, scratch):
    script = str(ROOT/'tests/Support/mariadb-offer-scheduler.php')
    barrier = scratch/('offer-'+env['SCHEMA_PREFIX'])
    env = dict(env, OFFER_BARRIER=str(barrier))
    def phase(name):
        return command('offer-'+name, php_args+[script], env=dict(env, OFFER_PHASE=name), timeout=180)
    phase('seed')
    with subprocess.Popen(php_args+[script], env=dict(env, OFFER_PHASE='first'),
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE) as first:
        try:
            until = time.monotonic()+30
            while not barrier.with_suffix('.ready').exists() and time.monotonic() < until:
                if first.poll() is not None:
                    raise AssertionError('First worker stopped before its claimed feed: '+first.communicate()[1].decode())
                time.sleep(0.05)
            if not barrier.with_suffix('.ready').exists():
                raise AssertionError('First connection did not reach the claimed physical read')
            phase('second')
            barrier.with_suffix('.release').write_text('release')
            out, err = first.communicate(timeout=40)
            if first.returncode:
                raise AssertionError('First worker failed: '+out.decode()+err.decode())
        finally:
            if first.poll() is None:
                first.kill(); first.wait(timeout=10)
    return json.loads(phase('verify').stdout)


if __name__ == '__main__':
    try:
        proof = run(exercise=exercise)
        if proof.get('skipped'):
            raise RuntimeError('Installed scheduler concurrency proof may not skip')
        raise SystemExit(0 if proof['complete'] else 1)
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
