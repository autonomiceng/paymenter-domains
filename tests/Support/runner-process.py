"""Small process stand-ins for runner cleanup checks."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time


def stopped_runs(case, ROOT):
    # Real processes exercise signal delivery and process-group cleanup, with
    # tiny app/runtime stand-ins instead of another native fixture boot.
    for mode, jobs in [('term', 1), ('term', 2), ('int', 2), ('timeout', 2), ('setup', 2), ('worker-setup', 2), ('escaped', 1)]:
        with case.subTest(mode=mode, jobs=jobs), tempfile.TemporaryDirectory() as temp:
            root = Path(temp).resolve()
            output = root/'output'; output.mkdir()
            pids = root/'pids'; pids.mkdir()
            (root/'Domains').mkdir()
            child = ("import os,subprocess,sys,time; from pathlib import Path; "
                     f"nested=subprocess.Popen([sys.executable,'-c','import time; time.sleep(30)'], start_new_session={mode == 'escaped'}); "
                     f"Path({str(pids)!r},str(os.getpid())).write_text(str(nested.pid)); "
                     "time.sleep(30)")
            script = f'''
import importlib.util, os, signal, sys
from pathlib import Path
from unittest import mock
sys.path.insert(0, {str(ROOT/'scripts')!r})
spec = importlib.util.spec_from_file_location('runner', {str(ROOT/'scripts/test.py')!r})
runner = importlib.util.module_from_spec(spec); spec.loader.exec_module(runner)
root = Path({str(root)!r})
output = root/'output'
def fixture(source, destination):
    (destination/'vendor').mkdir(parents=True)
    (destination/'vendor/autoload.php').write_text('synthetic')
    return {{}}
def runtime(**kwargs):
    base = root/'runtime'
    (base/'usr/bin').mkdir(parents=True)
    (base/'usr/bin/php8.4').write_text('synthetic')
    if {mode!r} == 'setup':
        os.kill(os.getpid(), signal.SIGTERM)
    return base, base/'usr/bin/php8.4', base/'usr/bin/curl.so'
run, execute, environments = runner.run, runner.execute, runner.test_workers.environments
def workers(*args, **kwargs):
    result = environments(*args, **kwargs)
    if {mode!r} == 'worker-setup':
        os.kill(os.getpid(), signal.SIGTERM)
    return result
def commands(*args, **kwargs):
    args = list(args)
    args[0] = [('runner', [sys.executable, '-c', {child!r}])]*{jobs}
    args[5] = None
    return run(*args, **kwargs)
def timed(*args, **kwargs):
    kwargs['timeout'] = .5
    return execute(*args, **kwargs)
with mock.patch.object(runner, 'ROOT', root), \\
     mock.patch.object(runner, 'manifest', return_value={{'native': ['tests/probe.py']}}), \\
     mock.patch.object(runner.tempfile, 'mkdtemp', return_value=str(output)), \\
     mock.patch.object(runner.native, 'prepare', side_effect=fixture), \\
     mock.patch.object(runner, 'prepare', side_effect=runtime), \\
     mock.patch.object(runner, 'run', side_effect=commands), \\
     mock.patch.object(runner.test_workers, 'environments', side_effect=workers), \\
     mock.patch.object(runner, 'execute', side_effect=timed if {mode!r} == 'timeout' else execute), \\
     mock.patch.dict(os.environ, {{'PAYMENTER_TEST_APP': 'synthetic'}}), \\
     mock.patch.object(sys, 'argv', ['test.py', '--jobs', {str(jobs)!r}, '--only', 'tests/probe.py']):
    raise SystemExit(runner.main())
'''
            with subprocess.Popen([sys.executable, '-c', script], stdout=subprocess.PIPE,
                                  stderr=subprocess.PIPE) as process:
                try:
                    if mode in ['term', 'int', 'escaped']:
                        deadline = time.monotonic() + 5
                        while len(list(pids.iterdir())) < jobs and process.poll() is None:
                            if time.monotonic() >= deadline:
                                case.fail('Commands never became ready')
                            time.sleep(.01)
                        case.assertEqual(len(list(pids.iterdir())), jobs)
                        os.kill(process.pid, signal.SIGINT if mode == 'int' else signal.SIGTERM)
                    stdout, stderr = process.communicate(timeout=8)
                    expected = 1 if mode == 'timeout' else 130 if mode == 'int' else 143
                    case.assertEqual(process.returncode, expected, (stdout, stderr))
                    case.assertFalse((output/'paymenter').exists())
                    case.assertFalse((output/'workers').exists())
                    case.assertFalse((root/'runtime').exists())
                    receipt = json.loads((output/'receipt.json').read_text())
                    case.assertFalse(receipt['complete'])
                    if mode == 'timeout':
                        case.assertTrue(receipt['results'][0]['timed_out'])
                    for marker in pids.iterdir():
                        checked = [marker.name] if mode == 'escaped' else [marker.name, marker.read_text()]
                        for pid in checked:
                            stat = Path('/proc')/pid/'stat'
                            deadline = time.monotonic() + 1
                            while True:
                                try: state = stat.read_text().split()[2]
                                except (FileNotFoundError, ProcessLookupError): state = None
                                if state in [None, 'Z', 'X'] or time.monotonic() >= deadline:
                                    break
                                time.sleep(.01)
                            case.assertIn(state, [None, 'Z', 'X'])
                finally:
                    if process.poll() is None:
                        process.kill()
                    for marker in pids.iterdir():
                        for pid in [marker.name, marker.read_text()]:
                            try: os.kill(int(pid), signal.SIGKILL)
                            except ProcessLookupError: pass

