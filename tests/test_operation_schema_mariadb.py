#!/usr/bin/env python3
"""Socket-only MariaDB check of operation time columns, keyed claims and evidence references.

With explicit_defaults_for_timestamp OFF, no time column gains a default or ON UPDATE, a drifted ON UPDATE is refused,
and an attempt update keeps its claim time. Two independent connections never hold one step's claim at once.
References to another target are refused and owned recovery restores them.
"""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import sys
from test_schema_mariadb import ROOT, run

CASES = ['foreign schema', 'case-distinct schema', 'case-distinct table']


def exercise(command, php_args, env, scratch):
    result = command('operation-schema-'+(env['SCHEMA_PREFIX'] or 'plain'),
                     php_args+[str(ROOT/'tests/Support/mariadb-operation-schema.php')], env=env, timeout=180)
    proof = json.loads(result.stdout.decode().splitlines()[-1])
    if not proof['complete'] or proof['prefix'] != env['SCHEMA_PREFIX'] or proof['refused_and_recovered'] != CASES \
            or proof['time_columns_explicit'] != 14 or proof['claim_time_kept'] is not True \
            or proof['on_update_drift_refused'] is not True or proof['keyed_claims_independent'] is not True:
        raise AssertionError('Operation schema reference proof incomplete')
    return dict(proof, real_provider_calls=0)


if __name__ == '__main__':
    try:
        result = run(exercise=exercise)
        raise SystemExit(77 if result.get('skipped') else (0 if result['complete'] else 1))
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
