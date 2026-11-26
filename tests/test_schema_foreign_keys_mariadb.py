#!/usr/bin/env python3
"""Socket-only MariaDB check that schema verification compares each foreign key's exact target and actions.

Test-local parent and child tables, with and without a prefix, on case-sensitive identifiers: the healthy key passes;
a foreign schema, a case-distinct schema or table and a changed action are refused on fresh and cached comparisons and
change the fingerprint; owned recovery restores a missing key and leaves a wrong one for refusal.
"""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import sys
from test_schema_mariadb import ROOT, run

CASES = ['foreign schema', 'case-distinct schema', 'case-distinct table', 'changed action']


def exercise(command, php_args, env, scratch):
    result = command('schema-foreign-keys-'+(env['SCHEMA_PREFIX'] or 'plain'),
                     php_args+[str(ROOT/'tests/Support/mariadb-schema-foreign-keys.php')], env=env, timeout=180)
    proof = json.loads(result.stdout.decode().splitlines()[-1])
    if not proof['complete'] or proof['prefix'] != env['SCHEMA_PREFIX'] or proof['refused_and_recovered'] != CASES:
        raise AssertionError('Foreign key verification proof incomplete')
    return dict(proof, real_provider_calls=0)


if __name__ == '__main__':
    try:
        result = run(exercise=exercise)
        raise SystemExit(77 if result.get('skipped') else (0 if result['complete'] else 1))
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
