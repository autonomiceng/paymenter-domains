# MIT, Copyright (c) 2026 Autonomic Engineering. Live collector I/O over private synthetic roots.
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
DISABLED = ('curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,'
            'socket_connect,socket_sendto,exec,system,passthru,shell_exec,popen,pcntl_exec,mail')


def collector_fixture(env, source=None):
    with tempfile.TemporaryDirectory(prefix='conversion-inventory-') as directory:
        base = Path(directory)
        cron = base/'crontab'
        cron.write_text('#!/bin/sh\nbase='+shlex.quote(directory)+'\ncat "$base/out"\ncat "$base/err" >&2\nexit "$(cat "$base/code")"\n')
        cron.chmod(0o700)
        environment = dict(env, PATH=directory+':'+env.get('PATH', '/usr/bin:/bin'))
        # Only this collector fixture enables proc_open; network and all other shell APIs remain disabled.
        argv = [str(ROOT/'scripts/php-test'), '-d', 'disable_functions='+DISABLED,
                str(ROOT/'tests/Support/conversion-inventory.php'), directory]
        if source:
            argv.append(str(source))
        return subprocess.run(argv, cwd=ROOT, env=environment, capture_output=True, timeout=30)


class CollectorTests(unittest.TestCase):
    def test_real_collector_filesystem_and_crontab_subprocess(self):
        result = collector_fixture(os.environ)
        self.assertEqual(result.returncode, 0, (result.stdout+result.stderr).decode())
        self.assertIn(b'contracts passed', result.stdout)


if __name__ == '__main__':
    unittest.main()
