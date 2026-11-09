# MIT, Copyright (c) 2026 Autonomic Engineering. No Docker calls: MariaDB schema harness refusal regressions.
import ast
from collections import Counter
import contextlib
import io
import itertools
import os
import signal
from pathlib import Path
import tempfile
import unittest
from unittest import mock

import test_schema_mariadb as schema_harness


class MariaGuardTests(unittest.TestCase):
    def test_remote_fixtures_use_reserved_test_namespace(self):
        tree = ast.parse(Path(__file__).read_text())
        hosts = Counter(node.value for node in ast.walk(tree)
                        if isinstance(node, ast.Constant) and isinstance(node.value, str)
                        and node.value.startswith(('tcp://' + 'remote.', 'ssh://' + 'remote.')))
        # Covers both host refusals together.
        self.assertEqual(hosts, Counter({'tcp://' + 'remote.test': 2}))

    def environment(self, directory, **extra):
        return dict(TMPDIR=directory, PATH='/usr/bin:/bin', CONVERSION_MARIADB_PHP='/synthetic/php',
                    PAYMENTER_TEST_APP='/synthetic/app', **extra)

    def test_restores_handlers_after_skip_failure_and_receipt_error(self):
        for outcome in ['skip', 'failure', 'receipt_error', 'setup_error', 'owner_error', 'handler_setup_error']:
            with self.subTest(outcome=outcome), tempfile.TemporaryDirectory() as directory:
                handlers = {sig: signal.getsignal(sig) for sig in [signal.SIGTERM, signal.SIGINT]}
                sentinels = {sig: lambda *args: None for sig in handlers}
                for sig, handler in sentinels.items(): signal.signal(sig, handler)
                try:
                    environment = self.environment(directory, DOCKER_HOST='tcp://remote.test')
                    if outcome == 'setup_error': environment['TMPDIR'] = 'relative'
                    original_signal = signal.signal
                    installs = 0
                    def install(sig, handler):
                        nonlocal installs
                        if callable(handler) and handler not in sentinels.values():
                            installs += 1
                            if installs == 2: raise OSError('handler setup failed')
                        return original_signal(sig, handler)
                    with mock.patch.dict(os.environ, environment, clear=True), \
                         mock.patch.object(schema_harness.shutil, 'which', return_value=None if outcome in ['skip', 'receipt_error'] else '/docker'), \
                         mock.patch.object(Path, 'write_text', side_effect=OSError('receipt failed')) if outcome == 'receipt_error' else contextlib.nullcontext(), \
                         mock.patch.object(schema_harness.secrets, 'token_hex', side_effect=OSError('owner failed')) if outcome == 'owner_error' else contextlib.nullcontext(), \
                         mock.patch.object(signal, 'signal', side_effect=install) if outcome == 'handler_setup_error' else contextlib.nullcontext(), \
                         contextlib.redirect_stdout(io.StringIO()):
                        if outcome == 'skip': self.assertIn('skipped', schema_harness.run())
                        else:
                            with self.assertRaises((RuntimeError, OSError, ValueError)): schema_harness.run()
                    for sig, handler in sentinels.items(): self.assertIs(signal.getsignal(sig), handler)
                finally:
                    for sig, handler in handlers.items(): signal.signal(sig, handler)

    def test_schema_fixture_overrides_inherited_database_url(self):
        with mock.patch.dict(os.environ, {'DB_URL': 'mysql://operator:synthetic@localhost/host_database?unix_socket=/host/mysql.sock',
                                          'DB_DATABASE': 'host_database', 'DB_SOCKET': '/host/mysql.sock'}):
            env = schema_harness.php_environment('/owned/app', '/owned/socket', 'owned_database', 'synthetic', 'p_')
        self.assertEqual(env['DB_URL'], '')
        self.assertEqual(env['DB_SOCKET'], '/owned/socket')
        self.assertEqual(env['DB_DATABASE'], 'owned_database')
        self.assertEqual(env['SCHEMA_PREFIX'], 'p_')

    def test_schema_remote_host_refuses_without_any_docker_call(self):
        with tempfile.TemporaryDirectory() as directory, mock.patch.dict(os.environ, self.environment(directory, DOCKER_HOST='tcp://remote.test'), clear=True), \
             mock.patch.object(schema_harness.shutil, 'which', return_value='/usr/bin/docker'), mock.patch.object(schema_harness.subprocess, 'run') as called, contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(ValueError, 'local Unix-socket'):
                schema_harness.run()
            called.assert_not_called()


if __name__ == '__main__':
    unittest.main()
