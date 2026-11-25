"""Installed lifecycles start early and leave workers for ordinary native tests; ordinary native tests may run beside a browser case."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from concurrent.futures import ThreadPoolExecutor
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
spec = importlib.util.spec_from_file_location('installed_schedule_runner', ROOT/'scripts/test.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


def ready_pipe(root, name):
    path = root/name
    os.mkfifo(path)
    return path


class InstalledSchedulingTests(unittest.TestCase):
    def test_installed_lifecycles_start_early_without_occupying_every_worker(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'Domains').mkdir()
            output = root/'output'; output.mkdir()
            ready = [ready_pipe(root, f'ready-{i}') for i in range(2)]
            release = [ready_pipe(root, f'release-{i}') for i in range(2)]
            scripts = []
            for i in range(4):
                path = root/f'test_installed_package_{i}.py'
                body = f"from pathlib import Path; Path({str(root/f'started-{i}')!r}).write_text('started'); "
                if i < 2:
                    body += (f"Path({str(ready[i])!r}).write_text('ready'); "
                             f"assert Path({str(release[i])!r}).read_text() == 'release'; ")
                path.write_text(body)
                scripts.append(('native', [sys.executable, str(path)]))
            path = root/'ordinary.py'
            body = "from pathlib import Path; "
            body += ''.join(f"assert Path({str(pipe)!r}).read_text() == 'ready'; " for pipe in ready)
            body += ''.join(f"assert not Path({str(root/f'started-{i}')!r}).exists(); " for i in (2, 3))
            body += ''.join(f"Path({str(pipe)!r}).write_text('release'); " for pipe in release)
            path.write_text(body)
            commands = runner.order_commands([('native', [sys.executable, str(path)]), *scripts])
            self.assertEqual([argv[-1] for _, argv in commands[:4]], [argv[-1] for _, argv in scripts])
            finished, submitted = [], {}
            def command(index, worker):
                subprocess.run(commands[index][1], check=True, timeout=10)
                return index
            with ThreadPoolExecutor(max_workers=4) as pool:
                runner.test_scheduling.parallel(pool, commands, {'DOMAINS_TEST_CGROUP': 'test'}, 4,
                                                threading.Event(), threading.Lock(), command,
                                                lambda index, value: finished.append((index, value)), submitted)
            self.assertEqual(sorted(finished), [(i, i) for i in range(len(commands))])
            self.assertEqual(submitted, {})
            self.assertTrue(all((root/f'started-{i}').exists() for i in range(4)))

    def test_bounded_browser_case_admits_four_ordinary_native_commands_and_no_lifecycle(self):
        commands = [('ui', ['tests/test_browser.py']),
                    *[('native', [f'tests/test_installed_package_{i}.py']) for i in range(2)],
                    *[('native', [f'tests/ordinary-{i}-test.php']) for i in range(6)]]

        class Future:
            def __init__(self, index):
                self.index = index

            def result(self):
                return self.index

        def schedule(env):
            pending, beside = [], []

            class Pool:
                def submit(pool, function, index, worker):
                    if any(commands[row][0] == 'ui' for row in pending):
                        beside.append(index)
                        self.assertLessEqual(len(pending), 4)
                    pending.append(index)
                    return Future(index)

            def wait_for(futures, timeout, return_when):
                # Native commands finish before the browser case, one at a time.
                future = min(futures, key=lambda f: commands[f.index][0] == 'ui')
                pending.remove(future.index)
                return {future}, set(futures) - {future}

            runner.test_scheduling.parallel(Pool(), commands, env, 10, threading.Event(), threading.Lock(),
                                            None, lambda index, value: None, {}, wait_for=wait_for)
            return beside

        self.assertEqual(schedule({'DOMAINS_TEST_CGROUP': 'test'}), [3, 4, 5, 6, 7, 8])
        self.assertEqual(schedule({}), [])


if __name__ == '__main__':
    unittest.main()
