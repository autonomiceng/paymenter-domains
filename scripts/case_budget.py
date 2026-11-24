"""Limit one test's process tree in the suite's delegated cgroup v2 subtree."""
import argparse
import json
import os
from pathlib import Path
import resource
import signal
import sys
import time
import uuid

from process_supervisor import STOP_SIGNALS, supervise


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
    parser = argparse.ArgumentParser()
    parser.add_argument('--name', required=True)
    parser.add_argument('--test-scope', action='store_true', required=True)
    parser.add_argument('--wall', type=float, required=True)
    parser.add_argument('--memory', type=int, required=True)
    parser.add_argument('--receipt', type=Path, required=True)
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if not 0 < args.wall <= 65 or not 0 < args.memory <= 768*1024**2 or args.command[:1] != ['--'] or len(args.command) < 2:
        parser.error('Invalid test limits or command')
    group = Path(os.environ['DOMAINS_TEST_CGROUP'])/('test-'+uuid.uuid4().hex)
    group.mkdir()
    result = None
    try:
        (group/'memory.max').write_text(str(args.memory))
        (group/'memory.swap.max').write_text('0')
        (group/'memory.oom.group').write_text('1')
        started = time.monotonic()
        controls = {prefix+key for prefix in ('DOMAINS_TEST_', 'PAY_TEST_')
                    for key in ('CGROUP', 'BUDGET', 'RECEIPTS', 'JOBS', 'OVERLAY', 'LIMITS')}
        env = {key: value for key, value in os.environ.items() if key not in controls}
        def enter_cgroup():
            # This dedicated, single-threaded controller forks directly into the test exec.
            # Join before the test can start any children.
            (group/'cgroup.procs').write_text('0')
        result = supervise(args.command[1:], grace_seconds=1, env=env, wall=args.wall, preexec_fn=enter_cgroup)
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
    code = result or int(bool(events['oom_kill']) or elapsed > args.wall or max(rss, peak) > args.memory)
    data = dict(exit=code, process_exit=result, wall_seconds=elapsed, memory_bytes=peak,
                memory_method='per_test_cgroup', peak_rss_bytes=rss, memory_events=events,
                oom_kill=events['oom_kill'], cpu_seconds=int(cpu['usage_usec'])/1e6,
                limits=dict(wall_seconds=args.wall, memory_bytes=args.memory))
    if os.environ.get('DOMAINS_TEST_LIMITS') == 'wide':
        data['memory_events'] = {key: str(value) for key, value in events.items()}
    pending = args.receipt.with_name(args.receipt.name+'.'+group.name+'.pending')
    try:
        pending.write_text(json.dumps(data)+'\n')
        pending.replace(args.receipt)
    finally:
        pending.unlink(missing_ok=True)
    return code


if __name__ == '__main__':
    # Handle cancellation in supervise while the child is alive. Defer it during
    # cgroup setup, drain and receipt publication so cancellation cannot strand a group.
    mask = signal.pthread_sigmask(signal.SIG_BLOCK, STOP_SIGNALS)
    try:
        sys.exit(main())
    finally:
        for number in STOP_SIGNALS:
            signal.signal(number, signal.SIG_DFL)
        signal.pthread_sigmask(signal.SIG_SETMASK, mask)
