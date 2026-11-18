"""Limit one test's process tree in the suite's delegated cgroup v2 subtree."""
import argparse
import json
import os
from pathlib import Path
import resource
import subprocess
import sys
import time
import uuid


def drain(group):
    (group/'cgroup.kill').write_text('1')
    # The outer command timeout gives cleanup two seconds beyond the test limit.
    deadline = time.monotonic() + 1
    while 'populated 0' not in (group/'cgroup.events').read_text().splitlines():
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError(f'Test cgroup did not drain: {group}')
        time.sleep(min(0.01, remaining))


def main():
    if sys.argv[1] == '--enter':
        (Path(sys.argv[2])/'cgroup.procs').write_text(str(os.getpid()))
        env = {k: v for k, v in os.environ.items() if k not in
               {'DOMAINS_TEST_CGROUP', 'DOMAINS_TEST_BUDGET', 'DOMAINS_TEST_RECEIPTS', 'DOMAINS_TEST_JOBS'}}
        os.execvpe(sys.argv[3], sys.argv[3:], env)
    parser = argparse.ArgumentParser()
    parser.add_argument('--name', required=True)
    parser.add_argument('--test-scope', action='store_true', required=True)
    parser.add_argument('--wall', type=float, required=True)
    parser.add_argument('--memory', type=int, required=True)
    parser.add_argument('--receipt', type=Path, required=True)
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if not 0 < args.wall <= 65 or not 0 < args.memory <= 768*1024**2 or args.command[:1] != ['--']:
        parser.error('Invalid test limits or command')
    group = Path(os.environ['DOMAINS_TEST_CGROUP'])/('test-'+uuid.uuid4().hex)
    group.mkdir()
    result = None
    try:
        (group/'memory.max').write_text(str(args.memory))
        (group/'memory.swap.max').write_text('0')
        (group/'memory.oom.group').write_text('1')
        started = time.monotonic()
        result = subprocess.run(['timeout', '--kill-after=0.2s', str(args.wall), sys.executable,
                                 __file__, '--enter', str(group), *args.command[1:]])
        elapsed = time.monotonic()-started
    finally:
        drain(group)
        try:
            if result is not None:
                peak = int((group/'memory.peak').read_text())
                events = {k: int(v) for k, v in (line.split() for line in (group/'memory.events').read_text().splitlines())}
                cpu = dict(line.split() for line in (group/'cpu.stat').read_text().splitlines())
        finally:
            group.rmdir()

    rss = resource.getrusage(resource.RUSAGE_CHILDREN).ru_maxrss*1024
    code = result.returncode or int(bool(events['oom_kill']) or elapsed > args.wall or max(rss, peak) > args.memory)
    data = dict(exit=code, process_exit=result.returncode, wall_seconds=elapsed, memory_bytes=peak,
                memory_method='per_test_cgroup', peak_rss_bytes=rss, memory_events=events,
                oom_kill=events['oom_kill'], cpu_seconds=int(cpu['usage_usec'])/1e6,
                limits=dict(wall_seconds=args.wall, memory_bytes=args.memory))
    args.receipt.write_text(json.dumps(data)+'\n')
    return code


if __name__ == '__main__':
    sys.exit(main())
