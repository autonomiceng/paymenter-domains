#!/usr/bin/env python3
"""Keep ownership of command descendants after their original parents exit."""
import argparse
import ctypes
import os
from pathlib import Path
import signal
import subprocess
import time


class Shutdown(BaseException):
    def __init__(self, signum):
        self.signum = signum


def children(pid):
    found = set()
    paths = list(Path(f'/proc/{pid}/task').glob('*/children'))
    if pid == os.getpid():
        # An unavailable inventory is not an empty, verified child list.
        found.update(int(child) for child in Path(f'/proc/{pid}/task/{pid}/children').read_text().split())
        if not paths:
            raise OSError('Kernel child inventory is unavailable')
    for path in paths:
        try:
            found.update(int(child) for child in path.read_text().split())
        except FileNotFoundError:
            pass
    return found


def descendants(*, freeze=False):
    owned, pending = set(), list(children(os.getpid()))
    while pending:
        pid = pending.pop()
        if pid in owned:
            continue
        if freeze:
            try:
                os.kill(pid, signal.SIGSTOP)
            except ProcessLookupError:
                continue
        owned.add(pid)
        pending.extend(children(pid))
    return owned


def notify(pids, signum):
    for pid in pids:
        try:
            os.kill(pid, signum)
        except ProcessLookupError:
            pass


def reap(leader):
    while True:
        try:
            pid, status = os.waitpid(-1, os.WNOHANG)
        except ChildProcessError:
            return
        if not pid:
            return
        if leader is not None and pid == leader.pid:
            leader.returncode = os.waitstatus_to_exitcode(status)


def cleanup(leader, grace=0):
    if grace:
        notify(descendants(), signal.SIGTERM)
        deadline = time.monotonic()+grace
        while time.monotonic() < deadline:
            reap(leader)
            if not children(os.getpid()):
                return True
            time.sleep(.01)
    # Reparenting during teardown can reveal more adopted children. Repeat until
    # all have been killed/reaped, with a bound for uninterruptible processes.
    deadline = time.monotonic()+2
    while True:
        reap(leader)
        if not children(os.getpid()):
            return True
        notify(descendants(freeze=True), signal.SIGKILL)
        if time.monotonic() >= deadline:
            return False
        time.sleep(.01)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--grace-seconds', type=float, default=0)
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command[1:] if args.command[:1] == ['--'] else args.command
    if not command or args.grace_seconds < 0:
        parser.error('A command and nonnegative grace period are required.')
    # Linux reparents orphaned descendants here, rather than to the host init.
    libc = ctypes.CDLL(None, use_errno=True)
    if libc.prctl(36, 1, 0, 0, 0):  # PR_SET_CHILD_SUBREAPER
        raise OSError(ctypes.get_errno(), 'Cannot establish descendant ownership')
    children(os.getpid())
    def interrupted(signum, frame):
        # Set dispositions before unwinding: a second signal must not abort
        # the exception handler before it can drain owned descendants.
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        signal.signal(signal.SIGINT, signal.SIG_IGN)
        raise Shutdown(signum)
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    leader = None
    try:
        leader = subprocess.Popen(command)
        while True:
            pid, status = os.waitpid(-1, 0)
            if pid == leader.pid:
                leader.returncode = os.waitstatus_to_exitcode(status)
                break
        if not cleanup(leader):
            return 125
        return leader.returncode if leader.returncode >= 0 else 128-leader.returncode
    except Shutdown as error:
        cleanup(leader, args.grace_seconds)
        return 128+error.signum
    except BaseException:
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        signal.signal(signal.SIGINT, signal.SIG_IGN)
        cleanup(leader, args.grace_seconds)
        raise


if __name__ == '__main__':
    raise SystemExit(main())
