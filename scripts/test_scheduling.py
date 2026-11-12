"""Admit installed lifecycles early, with room for ordinary native work."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from concurrent.futures import FIRST_COMPLETED, wait
from pathlib import Path


def installed(kind, argv):
    name = Path(argv[-1]).name
    return kind == 'native' and name.endswith('.py') and (
        name.startswith('test_installed_package') or name == 'test_installed_namesilo_enrollment.py')


def parallel(pool, commands, env, jobs, stopped, scheduling, command, record, submitted, *, wait_for=wait):
    waiting, pending, available = list(range(len(commands))), {}, list(range(jobs))
    exclusive_running = False
    installed_limit = min(2, max(1, jobs-1))
    while pending or (waiting and not stopped.is_set()):
        while available and waiting and not stopped.is_set():
            position = 0
            index = waiting[position]
            active = sum(installed(*commands[row]) for row, _ in pending.values())
            # Bypass only blocked native lifecycles, never a category/exclusive barrier.
            if installed(*commands[index]) and active >= installed_limit:
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
                waiting.pop(position)
        if not pending:
            break
        done, _ = wait_for(pending, timeout=.1, return_when=FIRST_COMPLETED)
        for future in done:
            index, worker = pending.pop(future)
            record(index, future.result())
            submitted.pop(future, None)
            available.append(worker)
        if not pending:
            exclusive_running = False
