"""Admit installed lifecycles early, with room for ordinary native work."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from concurrent.futures import FIRST_COMPLETED, wait
import contextlib
from pathlib import Path

LOCAL_BUDGET = Path(__file__).resolve().parent/'case_budget.py'


def installed(kind, argv):
    name = Path(argv[-1]).name
    return kind == 'native' and name.endswith('.py') and (
        name.startswith('test_installed_package') or name == 'test_installed_namesilo_enrollment.py')


# At most this many ordinary native commands run beside a browser case, and only
# while every active case ceiling plus the controller reserve fits the scope.
BESIDE_UI = 4
CONTROLLER_RESERVE = 256*1024**2


def memory_ceiling(kind, env):
    """The memory cap the case budget gives one bounded command."""
    if (env.get('DOMAINS_TEST_CGROUP') and env.get('DOMAINS_TEST_BUDGET') == str(LOCAL_BUDGET)
            and env.get('DOMAINS_TEST_LIMITS') != 'wide'):
        return 512*1024**2
    return (768 if kind == 'ui' else 256)*1024**2


def scope_bytes(env):
    """The lowest memory limit on the delegated group or its ancestors, or None."""
    limits = []
    if group := env.get('DOMAINS_TEST_CGROUP'):
        for path in (Path(group), *Path(group).parents):
            with contextlib.suppress(OSError, ValueError):
                limits.append(int((path/'memory.max').read_text()))
    return min(limits, default=None)


def parallel(pool, commands, env, jobs, stopped, scheduling, command, record, submitted, *, wait_for=wait,
             retry=frozenset()):
    """Run commands on jobs workers; retry holds the indexes that failed in the timing receipt."""
    waiting, pending, available = list(range(len(commands))), {}, list(range(jobs))
    exclusive_running = None
    installed_limit = min(2, max(1, jobs-1))
    scope = scope_bytes(env)
    while pending or (waiting and not stopped.is_set()):
        while available and waiting and not stopped.is_set():
            beside_ui = exclusive_running is not None and commands[exclusive_running][0] == 'ui'
            if exclusive_running is not None and not (beside_ui and len(pending) <= BESIDE_UI):
                break
            # A known failure waiting behind the browser case runs before anything new.
            if beside_ui and (scope is None or retry.intersection(waiting)):
                break
            position = 0
            index = waiting[position]
            active = sum(installed(*commands[row]) for row, _ in pending.values())
            # Bypass only blocked native lifecycles, never a category/exclusive barrier.
            if installed(*commands[index]) and (beside_ui or active >= installed_limit):
                position = None
                for i, row in enumerate(waiting):
                    if commands[row][0] != 'native':
                        break
                    if not installed(*commands[row]):
                        position = i
                        break
                if position is None:
                    break
                index = waiting[position]
            kind, argv = commands[index]
            exclusive = kind == 'ui' or (not env.get('DOMAINS_TEST_CGROUP') and kind != 'lint'
                                         and argv[-1].endswith('.py')
                                         and Path(argv[-1]).name.startswith('test_installed_'))
            if (exclusive and pending) or (beside_ui and kind != 'native'):
                break
            if beside_ui and CONTROLLER_RESERVE + memory_ceiling(kind, env) + sum(
                    memory_ceiling(commands[row][0], env) for row, _ in pending.values()) > scope:
                break
            with scheduling:
                if stopped.is_set():
                    break
                worker = available.pop()
                future = pool.submit(command, index, worker)
                pending[future] = (index, worker)
                submitted[future] = index
                if exclusive:
                    exclusive_running = index
                waiting.pop(position)
        if not pending:
            break
        done, _ = wait_for(pending, timeout=.1, return_when=FIRST_COMPLETED)
        for future in done:
            index, worker = pending.pop(future)
            record(index, future.result())
            submitted.pop(future, None)
            available.append(worker)
            if index == exclusive_running:
                exclusive_running = None


def known_failures(commands, previous):
    """Indexes of commands that failed in the timing receipt; nothing starts beside UI while one waits."""
    return frozenset(index for index, (kind, argv) in enumerate(commands) if previous.get((kind, argv[-1]), (False,))[0])
