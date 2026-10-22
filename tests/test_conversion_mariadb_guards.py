# MIT, Copyright (c) 2026 Autonomic Engineering. No Docker calls: ownership and refusal regressions.
import contextlib
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

import test_conversion_mariadb as harness


class MariaGuardTests(unittest.TestCase):
    def environment(self, directory, **extra):
        return dict(TMPDIR=directory, PATH='/usr/bin:/bin', CONVERSION_MARIADB_PHP='/synthetic/php',
                    PAYMENTER_TEST_APP='/synthetic/app', CONVERSION_ENOM_CHECKOUT='/synthetic/source', **extra)

    def test_remote_host_refuses_without_any_docker_call(self):
        with tempfile.TemporaryDirectory() as directory, mock.patch.dict(os.environ, self.environment(directory, DOCKER_HOST='tcp://remote.invalid'), clear=True), \
             mock.patch.object(harness.shutil, 'which', return_value='/usr/bin/docker'), mock.patch.object(harness.subprocess, 'run') as called, contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, 'local Unix-socket'):
                harness.run()
            called.assert_not_called()

    def test_remote_context_only_reads_local_context_metadata(self):
        context = subprocess.CompletedProcess([], 0, json.dumps([{'Endpoints': {'docker': {'Host': 'ssh://remote.invalid'}}}]).encode(), b'')
        with tempfile.TemporaryDirectory() as directory, mock.patch.dict(os.environ, self.environment(directory, DOCKER_CONTEXT='remote'), clear=True), \
             mock.patch.object(harness.shutil, 'which', return_value='/usr/bin/docker'), mock.patch.object(harness.subprocess, 'run', return_value=context) as called, contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, 'Remote Docker contexts'):
                harness.run()
            self.assertEqual([c.args[0] for c in called.call_args_list], [['docker', 'context', 'inspect']])

    def timed_out_start(self, matches):
        owner = None; inspections = 0; stopped = []
        image_id = 'a'*64; container_id = 'b'*64
        def execute(argv, **kwargs):
            nonlocal owner, inspections
            self.assertNotIn('tcp://remote.invalid', str(argv))
            if argv[:3] == ['docker', 'context', 'inspect']:
                data = [{'Endpoints': {'docker': {'Host': 'unix:///synthetic/docker.sock'}}}]
            elif argv[:3] == ['docker', 'image', 'inspect']:
                data = [{'Id': 'sha256:'+image_id}]
            elif argv[:3] == ['docker', 'container', 'inspect']:
                inspections += 1
                if inspections == 1:
                    return subprocess.CompletedProcess(argv, 1, b'', b'not found')
                self.assertEqual(kwargs['env']['DOCKER_HOST'], 'unix:///synthetic/docker.sock')
                data = [{'Id': container_id, 'Config': {'Labels': {'domains-conversion-rehearsal': owner if matches else 'foreign-owner'}}}]
            elif argv[:2] == ['docker', 'run']:
                owner = argv[argv.index('--label')+1].split('=', 1)[1]
                metadata = json.loads((Path(argv[argv.index('-v')+1].split(':')[0]).parent/'container.json').read_text())
                self.assertEqual(metadata, {'name': harness.CONTAINER, 'label': owner, 'docker_host': 'unix:///synthetic/docker.sock'})
                raise subprocess.TimeoutExpired(argv, 1)
            elif argv[:2] == ['docker', 'stop']:
                stopped.append(argv[-1]);data = None
                self.assertEqual(kwargs['env']['DOCKER_HOST'], 'unix:///synthetic/docker.sock')
            else:
                data = None
            return subprocess.CompletedProcess(argv, 0, json.dumps(data).encode() if data else b'', b'')
        original_is_dir = Path.is_dir
        def directories(path):
            return True if str(path) == '/var/spool/cron/crontabs' else original_is_dir(path)
        with tempfile.TemporaryDirectory() as directory, mock.patch.dict(os.environ, self.environment(directory), clear=True), \
             mock.patch.object(harness.shutil, 'which', return_value='/synthetic/executable'), mock.patch.object(harness.subprocess, 'run', side_effect=execute), \
             mock.patch.object(harness.native, 'verify', return_value=(None, {'fixture': 'digest'})), mock.patch.object(harness, 'memory_gate'), \
             mock.patch.object(Path, 'is_dir', directories), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(subprocess.TimeoutExpired):
                harness.run()
            self.assertEqual(stopped, [container_id] if matches else [])
            receipt = json.loads(next(Path(directory).glob('c41r-*/receipt.json')).read_text())
            self.assertFalse(receipt['complete'])
            if matches:
                self.assertTrue(receipt['container_stopped'])
            else:
                self.assertNotIn('container_stopped', receipt)
            self.assertTrue(receipt['dependency_unchanged'])

    def test_timed_out_start_only_stops_label_owned_immutable_id(self):
        self.timed_out_start(True)

    def test_timed_out_start_never_stops_foreign_label(self):
        self.timed_out_start(False)


if __name__ == '__main__':
    unittest.main()
