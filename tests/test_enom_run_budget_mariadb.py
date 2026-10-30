#!/usr/bin/env python3
"""Socket-only production PDO upgrade, competing admission and exactly-once consumption."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import os
import selectors
import subprocess
import sys
from test_schema_mariadb import ROOT,run


def exercise(command,php_args,env,scratch):
    barriers=scratch/('budget-'+(env['SCHEMA_PREFIX'] or 'plain'));barriers.mkdir(mode=0o700)
    env=dict(env,BUDGET_BARRIERS=str(barriers));script=str(ROOT/'tests/Support/mariadb-enom-budget.php')
    def phase(name):return command('budget-'+name,php_args+[script],env=dict(env,BUDGET_PHASE=name),timeout=180)
    phase('seed')
    with (barriers/'migration.stderr').open('wb') as errors:
        child=subprocess.Popen(php_args+[script],env=dict(env,BUDGET_PHASE='migrate'),stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=errors)
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(child.stdout,selectors.EVENT_READ)
                if not selector.select(45):raise AssertionError('Native commit deadline')
            if child.stdout.readline()!=b'native_committed\n':raise AssertionError('Migration failed before durable boundary')
            child.kill();child.wait(timeout=10)
        finally:
            if child.poll() is None:child.kill();child.wait(timeout=10)
            child.stdout.close();child.stdin.close()
    phase('recover');phase('recover')
    def race(name):
        children=[subprocess.Popen(php_args+[script],env=dict(env,BUDGET_PHASE=name,BUDGET_WORKER=str(i)),stdout=subprocess.PIPE,stderr=subprocess.PIPE) for i in range(4)]
        proofs=[]
        try:
            for child in children:
                out,err=child.communicate(timeout=45)
                if child.returncode:raise AssertionError('Budget racer failed: '+err.decode())
                proofs.append(json.loads(out))
        finally:
            for child in children:
                if child.poll() is None:child.kill();child.wait(timeout=10)
        return [p['outcome'] for p in proofs]
    admitted=race('allocate')
    if admitted.count('allocated')!=1 or admitted.count('refused')!=3:raise AssertionError('Admission conservation failed')
    phase('reserve');consumed=race('consume')
    if consumed.count('consumed')!=1 or consumed.count('refused')!=3:raise AssertionError('Permit exactly-once failed')
    return json.loads(phase('verify').stdout)

if __name__=='__main__':
    try:
        proof=run(exercise=exercise)
        if proof.get('skipped'):raise RuntimeError('Budget MariaDB acceptance may not skip')
        raise SystemExit(0 if proof['complete'] else 1)
    except Exception as error:
        print(type(error).__name__+': '+str(error),file=sys.stderr);raise SystemExit(1)
