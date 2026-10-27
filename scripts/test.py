#!/usr/bin/env python3
"""Run registered offline suites with pinned PHP and optional pristine native fixture."""
# Adapted from Paymenter Enom scripts/test.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import argparse
from contextlib import contextmanager
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
import json
import math
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
SUPERVISOR = Path(__file__).with_name('process_supervisor.py')
CATEGORIES = ['lint', 'provider', 'contracts', 'native', 'ui', 'runner', 'package', 'mariadb']
ORDER = ['lint', 'provider', 'contracts', 'runner', 'package', 'ui', 'native', 'mariadb']
WORKER_MEMORY_MIB = 1024


def kill_tree(pid):
    """Freeze parents before discovering children, including detached sessions."""
    owned, pending, stopped = set(), [pid], False
    try:
        try:
            os.killpg(pid, signal.SIGSTOP)
            stopped = True
        except ProcessLookupError:
            pass
        while pending:
            current = pending.pop()
            if current in owned:
                continue
            try:
                os.kill(current, signal.SIGSTOP)
            except ProcessLookupError:
                continue
            owned.add(current)
            # A child may have been created by any thread in this process.
            for path in Path(f'/proc/{current}/task').glob('*/children'):
                try:
                    pending.extend(int(child) for child in path.read_text().split())
                except FileNotFoundError:
                    pass
    finally:
        for current in owned:
            try:
                os.kill(current, signal.SIGKILL)
            except ProcessLookupError:
                pass
        try:
            os.killpg(pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
    return stopped or bool(owned)


class Processes:
    """Cancel registered trees, including children racing with cancellation."""
    def __init__(self):
        self.lock = threading.Lock()
        self.stopped = False
        self.running = {}
        self.cancelled = set()
        self.watchdogs = {}

    def kill(self, index, process):
        if index in self.cancelled or process.poll() is not None:
            return
        self.cancelled.add(index)
        try:
            os.kill(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            return
        # Keep the adopting supervisor alive to drain/reap descendants. This is
        # only an escalation if its own bounded cleanup does not return.
        def escalate():
            if process.poll() is None:
                kill_tree(process.pid)
        watchdog = threading.Timer(5, escalate)
        watchdog.daemon = True
        self.watchdogs[index] = watchdog
        watchdog.start()

    def register(self, index, process):
        with self.lock:
            self.running[index] = process
            if self.stopped:
                self.kill(index, process)

    def unregister(self, index):
        with self.lock:
            self.running.pop(index, None)
            watchdog = self.watchdogs.pop(index, None)
            if watchdog:
                watchdog.cancel()

    def cancel(self):
        with self.lock:
            self.stopped = True
            for index, process in self.running.items():
                self.kill(index, process)


def available_memory_mib():
    return next(int(line.split()[1])//1024 for line in Path('/proc/meminfo').read_text().splitlines()
                if line.startswith('MemAvailable:'))


def bounded_jobs(requested, memory_mib):
    if memory_mib < WORKER_MEMORY_MIB:
        raise ValueError('Memory budget must reserve at least 1024 MiB per worker.')
    # Serial runs cannot reduce concurrency further; preserve small-host support.
    if requested == 1:
        return 1
    # Reserve one GiB per worker and leave another GiB for the host. Installed
    # package commands retain their exclusive barrier and their own memory gate.
    budget = min(memory_mib, available_memory_mib()-1024)
    if budget < WORKER_MEMORY_MIB:
        raise ValueError('Insufficient available memory for one test worker (plus host reserve).')
    return min(requested, budget//WORKER_MEMORY_MIB)


def history(path):
    result = {}
    if path:
        for row in json.loads(Path(path).read_text())['results']:
            if row.get('cancelled'):
                continue
            seconds = float(row['seconds'])
            if not math.isfinite(seconds) or seconds < 0:
                raise ValueError('Invalid command duration in timing receipt.')
            result[(row['kind'], row['argv'][-1])] = (row['exit'] != 0, seconds)
    return result


def order_commands(commands, previous=None):
    previous = previous or {}
    def priority(item):
        index, (kind, argv) = item
        failed, seconds = previous.get((kind, argv[-1]), (False, math.inf))
        return ORDER.index(kind), not failed, seconds, index
    return [command for _, command in sorted(enumerate(commands), key=priority)]


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


def shutdown(process, graceful, *, supervised):
    graceful = max(graceful, 1) if supervised else graceful
    if graceful:
        try:
            # The supervisor forwards TERM exactly once to each descendant;
            # signalling its group as well could interrupt a finally block twice.
            if supervised:
                os.kill(process.pid, signal.SIGTERM)
            else:
                os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            stdout, stderr = process.communicate(timeout=graceful+3 if supervised else graceful)
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            return stdout, stderr
        except subprocess.TimeoutExpired:
            pass
    if supervised:
        kill_tree(process.pid)
    else:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
    try:
        return process.communicate(timeout=1)
    except subprocess.TimeoutExpired as drain:
        return drain.output or b'', drain.stderr or b''


def execute(argv, *, cwd, env, timeout=60, graceful=0, processes=None, index=None, rss_path=None):
    if rss_path is not None:
        argv = [sys.executable, str(SUPERVISOR), '--grace-seconds', str(max(graceful, 1)), '--',
                '/usr/bin/time', '-f', '%M', '-o', str(rss_path), '--', *argv]
    with subprocess.Popen(argv, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          start_new_session=True) as process:
        if processes is not None:
            processes.register(index, process)
        try:
            stdout, stderr = process.communicate(timeout=timeout)
            return stdout, stderr, process.returncode, False
        except subprocess.TimeoutExpired:
            stdout, stderr = shutdown(process, graceful, supervised=rss_path is not None)
            return stdout, stderr, 124, True
        except BaseException as error:
            error.captured_output = shutdown(process, graceful, supervised=rss_path is not None)
            raise
        finally:
            if processes is not None:
                processes.unregister(index)


@contextmanager
def timing(name, timings):
    started = time.monotonic()
    try:
        yield
    finally:
        timings[name] = time.monotonic() - started
        print(json.dumps({'timing': 'stage', 'stage': name, 'seconds': timings[name]}), flush=True)


@contextmanager
def cancel_on_error(processes):
    try:
        yield
    except BaseException:
        processes.cancel()
        raise


def run(commands, env, output, selected, suites, dependency=None, *, jobs=1, workers=None, worker_check=None, timings=None, only=None,
        fail_fast=False, requested_jobs=None, memory_mib=None):
    started_run = time.monotonic()
    timings = {} if timings is None else timings
    jobs = 1 if any(kind == 'mariadb' for kind, _ in commands) else jobs
    completed, attempted, submitted, interrupted_values = {}, {}, {}, {}
    captured, outputs_saved = {}, set()
    with timing('runtime_snapshot', timings):
        before = hashes()
        fingerprint = runtime_fingerprint() if jobs > 1 else None
    stopped, scheduling = threading.Event(), threading.Lock()
    processes, first_failure = Processes(), []

    def stop(index):
        with scheduling:
            if not stopped.is_set():
                first_failure.append(index)
            stopped.set()
            if fail_fast:
                processes.cancel()

    def command(index, worker):
        kind, argv = commands[index]
        started = time.monotonic()
        attempted[index] = (kind, argv, started)
        installed_package = Path(argv[-1]).name.startswith('test_installed_')
        timeout = (3000 if kind == 'mariadb' else 300 if kind == 'native' and installed_package
                   else 120 if kind == 'native' else 60)
        rss_path = output/f'{index:03d}.rss.txt'
        interrupted = None
        try:
            stdout, stderr, code, timed_out = execute(argv, cwd=ROOT, env=workers[worker] if workers else env,
                                                    timeout=timeout, graceful=60 if kind == 'mariadb' else 0,
                                                    processes=processes, index=index, rss_path=rss_path)
        except BaseException as error:
            interrupted = error
            stdout, stderr = getattr(error, 'captured_output', (b'', b''))
            code, timed_out = 130 if isinstance(error, KeyboardInterrupt) else None, False
        seconds = time.monotonic() - started
        cancelled = interrupted is not None or index in processes.cancelled
        if code and not cancelled:
            stop(index)
        started_guard = time.monotonic()
        unchanged, runtime_error = runtime_matches(before, fingerprint)
        guard_seconds = time.monotonic() - started_guard
        if not unchanged:
            stop(index)
        try:
            peak_rss = int(rss_path.read_text().splitlines()[-1])
        except (OSError, ValueError, IndexError):
            peak_rss = None
        value = stdout, stderr, {'kind': kind, 'argv': argv, 'exit': code, 'timed_out': timed_out,
                               'cancelled': cancelled, 'peak_rss_kib': peak_rss,
                               'runtime_unchanged': unchanged, 'runtime_error': runtime_error,
                               'skipped': kind == 'mariadb' and code == 77, 'seconds': seconds,
                               'guard_seconds': guard_seconds, 'started_seconds': started - started_run,
                               'finished_seconds': time.monotonic() - started_run}
        if interrupted is not None:
            interrupted_values[index] = value
            raise interrupted
        return value

    def record(index, value):
        stdout, stderr, result = value
        captured[index] = value
        completed[index] = result
        try:
            (output/f'{index:03d}.stdout.txt').write_bytes(stdout)
            (output/f'{index:03d}.stderr.txt').write_bytes(stderr)
        except OSError as error:
            result['output_error'] = type(error).__name__
            raise
        outputs_saved.add(index)
        captured.pop(index, None)
        print(json.dumps({'timing': 'command', 'index': index, **{key: result[key] for key in
                         ['kind', 'argv', 'exit', 'timed_out', 'seconds', 'guard_seconds',
                          'started_seconds', 'finished_seconds']}}), flush=True)
        if result['kind'] != 'lint' or result['exit']:
            print(stdout.decode(errors='replace').strip(), flush=True)
        if stderr:
            print(stderr.decode(errors='replace').strip(), file=sys.stderr, flush=True)

    runner_error = None
    try:
        with timing('commands', timings):
            if jobs == 1:
                for index in range(len(commands)):
                    record(index, command(index, 0))
                    if stopped.is_set():
                        break
            else:
                with ThreadPoolExecutor(max_workers=jobs) as pool, cancel_on_error(processes):
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
                                future = pool.submit(command, index, worker)
                                pending[future] = (index, worker)
                                submitted[future] = index
                                exclusive_running = exclusive
                                index += 1
                        if not pending:
                            break
                        done, _ = wait(pending, return_when=FIRST_COMPLETED)
                        # Drain every completed result before assigning another command.
                        for future in done:
                            position, worker = pending.pop(future)
                            record(position, future.result())
                            submitted.pop(future, None)
                            available.append(worker)
                        if not pending:
                            exclusive_running = False
    except BaseException as error:
        runner_error = type(error).__name__
        processes.cancel()
        # Finalize output and metadata together after the pool has drained.
        for future, index in submitted.items():
            if index not in captured and index not in outputs_saved:
                try:
                    captured[index] = future.result()
                except BaseException:
                    pass
        captured.update(interrupted_values)
        for index, value in list(captured.items()):
            if index not in outputs_saved:
                try:
                    record(index, value)
                except BaseException:
                    pass
        for index, (kind, argv, started) in attempted.items():
            if index not in completed:
                completed[index] = {'kind': kind, 'argv': argv, 'exit': None,
                                    'timed_out': False, 'cancelled': True, 'peak_rss_kib': None,
                                    'runtime_unchanged': False, 'runtime_error': runner_error,
                                    'skipped': False, 'seconds': time.monotonic()-started,
                                    'guard_seconds': 0, 'started_seconds': started-started_run,
                                    'finished_seconds': time.monotonic()-started_run}
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
    complete = runner_error is None and len(results) == len(commands) and all(r['exit'] == 0 and r['runtime_unchanged'] for r in results) and dependency_unchanged
    timings['run'] = time.monotonic() - started_run
    planned = {argv[-1] for kind, argv in commands if kind != 'lint'}
    receipt = {'runner_error': runner_error, 'timings': timings, 'only_tests': sorted(set(only or [])),
               'fail_fast': fail_fast, 'first_failure': first_failure[0] if first_failure else None,
               'commands_planned': len(commands),
               'plan': [{'index': index, 'kind': kind, 'argv': argv} for index, (kind, argv) in enumerate(commands)],
               'not_run': [{'index': index, 'kind': kind, 'argv': argv} for index, (kind, argv) in enumerate(commands) if index not in completed],
               'requested_jobs': requested_jobs or jobs, 'memory_mib': memory_mib,
               'categories': {kind: {'commands_run': len(rows),
                                     'wall_seconds': max(row['finished_seconds'] for row in rows)-min(row['started_seconds'] for row in rows),
                                     'child_seconds': sum(row['seconds'] for row in rows),
                                     'peak_child_rss_kib': max((row['peak_rss_kib'] or 0 for row in rows), default=0)}
                              for kind in selected if (rows := [row for row in results if row['kind'] == kind])},
               'omitted_tests': [path for paths in suites.values() for path in paths if path not in planned],
               'complete': complete, 'jobs': jobs, 'php_version': '8.4.27', 'results': results, 'runtime_sha256': before,
               'selected_suites': selected, 'omitted_suites': [s for s in CATEGORIES if s not in selected],
               'unimplemented_suites': [s for s, paths in suites.items() if not paths],
               'dependency': {'commit': native.COMMIT, 'lock_sha256': native.LOCK_SHA256, 'source_sha256': dependency[1]} if dependency else None,
               'skipped_suites': [r['kind'] for r in results if r['skipped']], 'dependency_unchanged': dependency_unchanged, 'dependency_error': dependency_error, 'real_provider_calls': 0,
               'scope': 'Synthetic offline tests and native ZIP install/retention; no live provider, delivered mail, shared-database or production acceptance.'}
    (output/'receipt.json').write_text(json.dumps(receipt, indent=2)+'\n')
    print(json.dumps({'complete': complete, 'commands_run': len(results), 'commands_planned': len(commands),
                      'first_failure': first_failure[0] if first_failure else None,
                      'cancelled_commands': sum(row['cancelled'] for row in results),
                      'only_tests': sorted(set(only or [])), 'receipt': str(output/'receipt.json')}))
    if (runner_error is None and len(results) == len(commands) and any(r['skipped'] for r in results) and dependency_unchanged
            and all(r['exit'] in [0, 77] and r['runtime_unchanged'] for r in results)):
        return 77
    return 130 if runner_error == 'KeyboardInterrupt' else 0 if complete else 1


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


def positive_integer(value):
    number = int(value)
    if number < 1:
        raise argparse.ArgumentTypeError('Must be positive.')
    return number


def default_suites():
    return [s for s in CATEGORIES if s != 'mariadb' and (s != 'native' or os.environ.get('PAYMENTER_TEST_APP'))]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    selection = parser.add_mutually_exclusive_group()
    selection.add_argument('--suite', choices=CATEGORIES, action='append')
    selection.add_argument('--only', nargs='+', action='extend', metavar='TEST_PATH',
                           help='Run only exact registered test paths; not a full boundary run.')
    parser.add_argument('--jobs', type=positive_integer,
                        default=os.environ.get('DOMAINS_TEST_JOBS', max(1, min(4, (os.cpu_count() or 1)//2))))
    parser.add_argument('--memory-mib', type=positive_integer, default=2048,
                        help='Worker memory reservation budget; one GiB per parallel worker.')
    parser.add_argument('--fail-fast', action='store_true', help='Kill in-flight process trees on the first failure.')
    parser.add_argument('--timings-from', metavar='RECEIPT', help='Prioritize previously failing and faster commands within each category.')
    args = parser.parse_args()
    os.umask(0o077)
    output = Path(tempfile.mkdtemp(prefix='domains-tests-'))
    selected = args.suite or []
    runtime = fixture = None
    workers = None
    timings = {}
    try:
        suites = manifest()
        previous = history(args.timings_from)
        Path(f'/proc/{os.getpid()}/task/{os.getpid()}/children').read_text()
        if not Path('/usr/bin/time').is_file():
            raise ValueError('GNU time (/usr/bin/time) is required for per-command peak RSS.')
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
               'PHPRC': '', 'PHP_INI_SCAN_DIR': '', 'TMPDIR': str(output), 'PYTHONDONTWRITEBYTECODE': '1'}
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
        commands = order_commands(commands, previous)
        if args.memory_mib < 2048 and any(Path(argv[-1]).name.startswith('test_installed_') for kind, argv in commands if kind != 'lint'):
            raise ValueError('Installed-package checks require a memory budget of at least 2048 MiB.')
        jobs = 1 if 'mariadb' in selected else bounded_jobs(args.jobs, args.memory_mib)
        worker_check = None
        if jobs > 1:
            with timing('worker_environments', timings):
                workers, fixture_snapshot, runtime_snapshot = test_workers.environments(env, output, jobs, runtime)
            worker_check = lambda: test_workers.verify(workers, fixture_snapshot, runtime_snapshot)
        return run(commands, env, output, selected, suites, dependency, jobs=jobs, workers=workers, worker_check=worker_check,
                   timings=timings, only=args.only, fail_fast=args.fail_fast, requested_jobs=args.jobs, memory_mib=args.memory_mib)
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
    def interrupted(signum, frame):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, interrupted)
    raise SystemExit(main())
