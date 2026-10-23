#!/usr/bin/env python3
"""Run registered offline suites with pinned PHP and optional pristine native fixture."""
# Adapted from Paymenter Enom scripts/test.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import argparse
from contextlib import contextmanager
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import time
from bootstrap import prepare
import native
import test_workers

ROOT = Path(__file__).resolve().parents[1]
CATEGORIES = ['lint', 'provider', 'contracts', 'native', 'ui', 'runner', 'package', 'mariadb']


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


def execute(argv, *, cwd, env, timeout=60, graceful=0):
    with subprocess.Popen(argv, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          start_new_session=True) as process:
        try:
            stdout, stderr = process.communicate(timeout=timeout)
            return stdout, stderr, process.returncode, False
        except subprocess.TimeoutExpired:
            if graceful:
                # Let opt-in resource owners run their finally cleanup before escalation.
                os.killpg(process.pid, signal.SIGTERM)
                try:
                    stdout, stderr = process.communicate(timeout=graceful)
                    try:
                        os.killpg(process.pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                    return stdout, stderr, 124, True
                except subprocess.TimeoutExpired:
                    pass
            # Descendants share the group: stop all of them before fixture cleanup.
            os.killpg(process.pid, signal.SIGKILL)
            try:
                stdout, stderr = process.communicate(timeout=1)
            except subprocess.TimeoutExpired as drain:
                # An escaped session may still own the pipes. Keep cumulative output.
                stdout, stderr = drain.output or b'', drain.stderr or b''
            return stdout, stderr, 124, True


@contextmanager
def timing(name, timings):
    started = time.monotonic()
    try:
        yield
    finally:
        timings[name] = time.monotonic() - started
        print(json.dumps({'timing': 'stage', 'stage': name, 'seconds': timings[name]}), flush=True)


def run(commands, env, output, selected, suites, dependency=None, *, jobs=1, workers=None, worker_check=None, timings=None, only=None):
    started_run = time.monotonic()
    timings = {} if timings is None else timings
    jobs = 1 if any(kind == 'mariadb' for kind, _ in commands) else jobs
    completed = {}
    with timing('runtime_snapshot', timings):
        before = hashes()
        fingerprint = runtime_fingerprint() if jobs > 1 else None
    stopped, scheduling = threading.Event(), threading.Lock()

    def command(index, worker):
        kind, argv = commands[index]
        started = time.monotonic()
        stdout, stderr, code, timed_out = execute(argv, cwd=ROOT, env=workers[worker] if workers else env, timeout=3000 if kind == 'mariadb' else (120 if kind == 'native' else 60), graceful=60 if kind == 'mariadb' else 0)
        seconds = time.monotonic() - started
        if code:
            with scheduling:
                stopped.set()
        started_guard = time.monotonic()
        unchanged, runtime_error = runtime_matches(before, fingerprint)
        guard_seconds = time.monotonic() - started_guard
        if not unchanged:
            with scheduling:
                stopped.set()
        return stdout, stderr, {'kind': kind, 'argv': argv, 'exit': code, 'timed_out': timed_out,
                               'runtime_unchanged': unchanged, 'runtime_error': runtime_error,
                               'skipped': kind == 'mariadb' and code == 77, 'seconds': seconds,
                               'guard_seconds': guard_seconds, 'started_seconds': started - started_run,
                               'finished_seconds': time.monotonic() - started_run}

    def record(index, value):
        stdout, stderr, result = value
        (output/f'{index:03d}.stdout.txt').write_bytes(stdout)
        (output/f'{index:03d}.stderr.txt').write_bytes(stderr)
        completed[index] = result
        print(json.dumps({'timing': 'command', 'index': index, **{key: result[key] for key in
                         ['kind', 'argv', 'exit', 'timed_out', 'seconds', 'guard_seconds',
                          'started_seconds', 'finished_seconds']}}), flush=True)
        if result['kind'] != 'lint' or result['exit']:
            print(stdout.decode(errors='replace').strip(), flush=True)
        if stderr:
            print(stderr.decode(errors='replace').strip(), file=sys.stderr, flush=True)

    with timing('commands', timings):
        if jobs == 1:
            for index in range(len(commands)):
                record(index, command(index, 0))
                if stopped.is_set():
                    break
        else:
            with ThreadPoolExecutor(max_workers=jobs) as pool:
                pending, index, available = {}, 0, list(range(jobs))
                exclusive_running = False
                while pending or (index < len(commands) and not stopped.is_set()):
                    while available and index < len(commands):
                        kind, argv = commands[index]
                        exclusive = kind != 'lint' and Path(argv[-1]).name.startswith('test_installed_')
                        # Installed fixtures need the whole pool, including integrity checks.
                        if exclusive_running or (exclusive and pending):
                            break
                        with scheduling:
                            if stopped.is_set():
                                break
                            worker = available.pop()
                            pending[pool.submit(command, index, worker)] = (index, worker)
                            exclusive_running = exclusive
                            index += 1
                    if not pending:
                        break
                    done, _ = wait(pending, return_when=FIRST_COMPLETED)
                    # Drain every completed result before assigning another command.
                    for future in done:
                        position, worker = pending.pop(future)
                        record(position, future.result())
                        available.append(worker)
                    if not pending:
                        exclusive_running = False
    results = [completed[index] for index in sorted(completed)]
    with timing('final_guards', timings):
        # A final guard catches changes after the last per-command check as well.
        unchanged, runtime_error = runtime_matches(before, fingerprint)
        if results and not unchanged:
            results[-1].update(runtime_unchanged=False, runtime_error=runtime_error)
        dependency_error = None
        try:
            dependency_unchanged = dependency is None or native.verify(dependency[0])[1] == dependency[1]
            if worker_check:
                worker_check()
        except (ValueError, RuntimeError, OSError, KeyError, TypeError, subprocess.CalledProcessError) as error:
            dependency_unchanged = False
            dependency_error = type(error).__name__
    complete = len(results) == len(commands) and all(r['exit'] == 0 and r['runtime_unchanged'] for r in results) and dependency_unchanged
    timings['run'] = time.monotonic() - started_run
    planned = {argv[-1] for kind, argv in commands if kind != 'lint'}
    receipt = {'timings': timings, 'only_tests': sorted(set(only or [])),
               'omitted_tests': [path for paths in suites.values() for path in paths if path not in planned],
               'complete': complete, 'jobs': jobs, 'php_version': '8.4.27', 'results': results, 'runtime_sha256': before,
               'selected_suites': selected, 'omitted_suites': [s for s in CATEGORIES if s not in selected],
               'unimplemented_suites': [s for s, paths in suites.items() if not paths],
               'dependency': {'commit': native.COMMIT, 'lock_sha256': native.LOCK_SHA256, 'source_sha256': dependency[1]} if dependency else None,
               'skipped_suites': [r['kind'] for r in results if r['skipped']], 'dependency_unchanged': dependency_unchanged, 'dependency_error': dependency_error, 'real_provider_calls': 0,
               'scope': 'Synthetic offline tests and native ZIP install/retention; no live provider, delivered mail, shared-database or production acceptance.'}
    (output/'receipt.json').write_text(json.dumps(receipt, indent=2)+'\n')
    print(json.dumps({'complete': complete, 'commands_run': len(results), 'commands_planned': len(commands), 'only_tests': sorted(set(only or [])), 'receipt': str(output/'receipt.json')}))
    if (len(results) == len(commands) and any(r['skipped'] for r in results) and dependency_unchanged
            and all(r['exit'] in [0, 77] and r['runtime_unchanged'] for r in results)):
        return 77
    return 0 if complete else 1


def runtime_fingerprint():
    root = ROOT/'Domains'
    def facts(stat):
        return (stat.st_ino, stat.st_mode, stat.st_size, stat.st_mtime_ns, stat.st_ctime_ns)
    result = {'.': facts(root.lstat())}
    pending = [(root, '')]
    while pending:
        directory, prefix = pending.pop()
        with os.scandir(directory) as entries:
            for entry in entries:
                name = prefix + entry.name
                result[name] = facts(entry.stat(follow_symlinks=False))
                if entry.is_dir(follow_symlinks=False):
                    pending.append((entry.path, name+'/'))
    return result


def runtime_matches(before, fingerprint=None):
    try:
        return hashes() == before and (fingerprint is None or runtime_fingerprint() == fingerprint), None
    except (ValueError, OSError) as error:
        return False, type(error).__name__


def positive_jobs(value):
    jobs = int(value)
    if jobs < 1:
        raise argparse.ArgumentTypeError('Jobs must be positive.')
    return jobs


def default_suites():
    return [s for s in CATEGORIES if s != 'mariadb' and (s != 'native' or os.environ.get('PAYMENTER_TEST_APP'))]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    selection = parser.add_mutually_exclusive_group()
    selection.add_argument('--suite', choices=CATEGORIES, action='append')
    selection.add_argument('--only', nargs='+', action='extend', metavar='TEST_PATH',
                           help='Run only exact registered test paths; not a full boundary run.')
    parser.add_argument('--jobs', type=positive_jobs,
                        default=os.environ.get('DOMAINS_TEST_JOBS', max(1, min(4, (os.cpu_count() or 1)//2))))
    args = parser.parse_args()
    os.umask(0o077)
    output = Path(tempfile.mkdtemp(prefix='domains-tests-'))
    selected = args.suite or []
    runtime = fixture = None
    workers = None
    timings = {}
    try:
        suites = manifest()
        requested = set(args.only or [])
        unknown = requested - {path for paths in suites.values() for path in paths}
        if unknown:
            raise ValueError('Unregistered test path: '+', '.join(sorted(unknown)))
        selected = ([kind for kind, paths in suites.items() if requested.intersection(paths)] if args.only
                    else list(dict.fromkeys(args.suite or default_suites())))
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
            with timing('native_fixture', timings):
                snapshot = native.prepare(source, fixture)
            dependency = (source, snapshot)
        binary = extension = None
        if selected != ['mariadb']:
            with timing('php_runtime', timings):
                runtime, binary, extension = prepare(native='native' in selected)
        env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8',
               'PHPRC': '', 'PHP_INI_SCAN_DIR': ''}
        if 'DOMAINS_UPGRADE_FROM' in os.environ:
            env['DOMAINS_UPGRADE_FROM'] = os.environ['DOMAINS_UPGRADE_FROM']
        if binary:
            env.update(PAYMENTER_TEST_PHP=str(binary), PAYMENTER_TEST_CURL_EXTENSION=str(extension))
        if 'mariadb' in selected:
            for name in ['TMPDIR', 'CONVERSION_MARIADB_PHP', 'PAYMENTER_TEST_APP', 'CONVERSION_ENOM_CHECKOUT', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'HOME', 'XDG_RUNTIME_DIR']:
                if name in os.environ:
                    env[name] = os.environ[name]
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
                commands.extend((kind, [sys.executable if p.endswith('.py') else php, p])
                                for p in paths if not requested or p in requested)
        if not commands:
            raise ValueError('No runnable suites selected.')
        jobs = 1 if 'mariadb' in selected else args.jobs
        worker_check = None
        if jobs > 1:
            with timing('worker_environments', timings):
                workers, fixture_snapshot, runtime_snapshot = test_workers.environments(env, output, jobs, runtime)
            worker_check = lambda: test_workers.verify(workers, fixture_snapshot, runtime_snapshot)
        return run(commands, env, output, selected, suites, dependency, jobs=jobs, workers=workers, worker_check=worker_check,
                   timings=timings, only=args.only)
    except BaseException as error:
        if not (output/'receipt.json').exists():
            (output/'receipt.json').write_text(json.dumps({'complete': False, 'phase': 'setup', 'error_type': type(error).__name__, 'selected_suites': selected,
                                                          'only_tests': args.only or [], 'timings': timings})+'\n')
            print('Setup failed; receipt: '+str(output/'receipt.json'), file=sys.stderr)
        raise
    finally:
        shutil.rmtree(output/'workers', ignore_errors=True)
        if runtime:
            shutil.rmtree(runtime)
        if fixture:
            shutil.rmtree(fixture, ignore_errors=True)


if __name__ == '__main__':
    raise SystemExit(main())
