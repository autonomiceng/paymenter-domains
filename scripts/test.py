#!/usr/bin/env python3
"""Run registered offline suites with pinned PHP and optional pristine native fixture."""
# Adapted from Paymenter Enom scripts/test.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
from bootstrap import prepare
import native

ROOT = Path(__file__).resolve().parents[1]
CATEGORIES = ['lint', 'provider', 'contracts', 'native', 'ui', 'runner', 'package']


def hashes():
    return native.hashes(ROOT/'Domains')


def manifest():
    suites = json.loads((ROOT/'tests/suites.json').read_text())
    if set(suites) != set(CATEGORIES)-{'lint'}:
        raise ValueError('Suite categories are incomplete.')
    paths = [p for names in suites.values() for p in names]
    discovered = {str(p.relative_to(ROOT)) for pattern in ['*-test.php', 'test_*.py', '*-test.cjs']
                  for p in (ROOT/'tests').rglob(pattern)}
    if any(p.endswith('.cjs') for p in paths):
        raise ValueError('JavaScript runner is not implemented.')
    if len(paths) != len(set(paths)) or set(paths) != discovered or not paths:
        raise ValueError('Missing, duplicate or unregistered suite.')
    return suites


def execute(argv, *, cwd, env, timeout=60):
    with subprocess.Popen(argv, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          start_new_session=True) as process:
        try:
            stdout, stderr = process.communicate(timeout=timeout)
            return stdout, stderr, process.returncode, False
        except subprocess.TimeoutExpired:
            # Descendants share the group: stop all of them before fixture cleanup.
            os.killpg(process.pid, signal.SIGKILL)
            try:
                stdout, stderr = process.communicate(timeout=1)
            except subprocess.TimeoutExpired as drain:
                # An escaped session may still own the pipes. Keep cumulative output.
                stdout, stderr = drain.output or b'', drain.stderr or b''
            return stdout, stderr, 124, True


def run(commands, env, output, selected, suites, dependency=None):
    before, results = hashes(), []
    for index, (kind, argv) in enumerate(commands):
        stdout, stderr, code, timed_out = execute(argv, cwd=ROOT, env=env, timeout=120 if kind == 'native' else 60)
        (output/f'{index:03d}.stdout.txt').write_bytes(stdout)
        (output/f'{index:03d}.stderr.txt').write_bytes(stderr)
        runtime_error = None
        try:
            unchanged = hashes() == before
        except (ValueError, OSError) as error:
            unchanged = False
            runtime_error = type(error).__name__
        results.append({'kind': kind, 'argv': argv, 'exit': code, 'timed_out': timed_out, 'runtime_unchanged': unchanged, 'runtime_error': runtime_error})
        if kind != 'lint' or code:
            print(stdout.decode(errors='replace').strip(), flush=True)
        if stderr:
            print(stderr.decode(errors='replace').strip(), file=sys.stderr, flush=True)
        if code or not unchanged:
            break
    dependency_error = None
    try:
        dependency_unchanged = dependency is None or native.verify(dependency[0])[1] == dependency[1]
    except (ValueError, RuntimeError, OSError, KeyError, TypeError, subprocess.CalledProcessError) as error:
        dependency_unchanged = False
        dependency_error = type(error).__name__
    complete = len(results) == len(commands) and all(r['exit'] == 0 and r['runtime_unchanged'] for r in results) and dependency_unchanged
    receipt = {'complete': complete, 'php_version': '8.4.26', 'results': results, 'runtime_sha256': before,
               'selected_suites': selected, 'omitted_suites': [s for s in CATEGORIES if s not in selected],
               'unimplemented_suites': [s for s, paths in suites.items() if not paths],
               'dependency': {'commit': native.COMMIT, 'lock_sha256': native.LOCK_SHA256, 'source_sha256': dependency[1]} if dependency else None,
               'dependency_unchanged': dependency_unchanged, 'dependency_error': dependency_error, 'real_provider_calls': 0,
               'scope': 'Synthetic offline tests; native fixture only, no Domains install/billing/UI/provider acceptance.'}
    (output/'receipt.json').write_text(json.dumps(receipt, indent=2)+'\n')
    print(json.dumps({'complete': complete, 'commands_run': len(results), 'commands_planned': len(commands), 'receipt': str(output/'receipt.json')}))
    return 0 if complete else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--suite', choices=CATEGORIES, action='append')
    args = parser.parse_args()
    os.umask(0o077)
    output = Path(tempfile.mkdtemp(prefix='domains-tests-'))
    selected = args.suite or []
    runtime = fixture = None
    try:
        suites = manifest()
        selected = list(dict.fromkeys(args.suite or [s for s in CATEGORIES if s != 'native' or os.environ.get('PAYMENTER_TEST_APP')]))
        for name in selected:
            if name != 'lint' and not suites[name]:
                if args.suite:
                    raise ValueError('Suite not implemented: '+name)
                print('Not implemented: '+name, flush=True)
        dependency = None
        if 'native' in selected:
            source = os.environ.get('PAYMENTER_TEST_APP')
            if not source:
                raise ValueError('Native suite requires PAYMENTER_TEST_APP.')
            fixture = output/'paymenter'
            snapshot = native.prepare(source, fixture)
            dependency = (source, snapshot)
        runtime, binary, extension = prepare(native='native' in selected)
        env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8',
               'PHPRC': '', 'PHP_INI_SCAN_DIR': '', 'PAYMENTER_TEST_PHP': str(binary), 'PAYMENTER_TEST_CURL_EXTENSION': str(extension)}
        if fixture:
            env.update(PAYMENTER_TEST_FIXTURE=str(fixture), PAYMENTER_TEST_NATIVE_EXTENSIONS=str(extension.parent),
                       LD_LIBRARY_PATH=str(runtime/'usr/lib/x86_64-linux-gnu'))
        php = str(ROOT/'scripts/php-test')
        commands = [('lint', [php, '-l', str(p.relative_to(ROOT))]) for area in ['Domains', 'tests']
                    for p in sorted((ROOT/area).rglob('*.php'))] if 'lint' in selected else []
        if 'lint' in selected:
            commands.append(('lint', [php, 'tests/php83-compatibility-test.php']))
        for kind, paths in suites.items():
            if kind in selected:
                commands.extend((kind, [sys.executable if p.endswith('.py') else php, p]) for p in paths)
        if not commands:
            raise ValueError('No runnable suites selected.')
        return run(commands, env, output, selected, suites, dependency)
    except BaseException as error:
        if not (output/'receipt.json').exists():
            (output/'receipt.json').write_text(json.dumps({'complete': False, 'phase': 'setup', 'error_type': type(error).__name__, 'selected_suites': selected})+'\n')
            print('Setup failed; receipt: '+str(output/'receipt.json'), file=sys.stderr)
        raise
    finally:
        if runtime:
            shutil.rmtree(runtime)
        if fixture:
            shutil.rmtree(fixture, ignore_errors=True)


if __name__ == '__main__':
    raise SystemExit(main())
