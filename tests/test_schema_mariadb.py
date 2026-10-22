#!/usr/bin/env python3
"""Opt-in socket-only fresh/retained native schema proof; no registrar calls."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
import native
from test_conversion_mariadb import memory_gate

IMAGE = 'mariadb:11.4.5'


def php_environment(app, socket, database, password, prefix):
    # Laravel's DB_URL can override even explicit socket/database settings.
    return dict(os.environ, APP_ENV='testing', APP_KEY='base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ=',
        APP_URL='http://schema.invalid', DB_CONNECTION='mysql', DB_DATABASE=database, DB_USERNAME='root',
        DB_PASSWORD=password, DB_SOCKET=str(socket), SCHEMA_PREFIX=prefix, DB_COLLATION='utf8mb4_unicode_ci',
        CACHE_STORE='array', QUEUE_CONNECTION='sync', SESSION_DRIVER='array', MAIL_MAILER='array',
        TELEMETRY_ENABLED='false', PAYMENTER_TEST_FIXTURE=str(app), CONVERSION_PHASE='schema',
        DB_URL='', PHPRC='', PHP_INI_SCAN_DIR='')


def run(*, exercise=None):
    os.umask(0o077)
    scratch_root = Path(os.environ.get('TMPDIR', ''))
    if not scratch_root.is_absolute() or not scratch_root.is_dir():
        raise ValueError('Set TMPDIR to an existing private scratch root.')
    scratch = Path(tempfile.mkdtemp(prefix='domains-schema-mariadb-', dir=scratch_root))
    receipt = {'complete': False, 'real_provider_calls': 0, 'image': IMAGE, 'commands': [], 'runs': []}
    began = time.monotonic()
    owner = secrets.token_hex(16)
    container = 'dom-schema-'+owner[:12]
    container_id = None
    attempted = False
    docker_env = None
    original = None
    previous = {}

    def command(role, argv, *, env=None, data=None, timeout=120, check=True):
        started = time.monotonic()
        result = subprocess.run(argv, cwd=ROOT, env=env if env is not None else docker_env,
                                input=data, capture_output=True, timeout=timeout)
        index = len(receipt['commands'])
        (scratch/f'{index:03d}-{role}.stdout').write_bytes(result.stdout)
        (scratch/f'{index:03d}-{role}.stderr').write_bytes(result.stderr)
        receipt['commands'].append({'role': role, 'exit': result.returncode, 'seconds': time.monotonic()-started})
        if check and result.returncode:
            raise RuntimeError(role+' failed; inspect private command logs: '+str(scratch))
        return result

    def terminate(signum, frame):
        raise KeyboardInterrupt('Termination requested; cleaning owned container.')

    try:
        for sig in (signal.SIGINT, signal.SIGTERM):
            previous[sig] = signal.signal(sig, terminate)
        if not shutil.which('docker'):
            receipt['skipped'] = 'Docker unavailable.'; return receipt
        if os.environ.get('DOCKER_HOST') and not os.environ['DOCKER_HOST'].startswith('unix://'):
            raise ValueError('Only a local Unix-socket Docker daemon is supported.')
        context = command('context', ['docker', 'context', 'inspect'], timeout=20)
        endpoint = json.loads(context.stdout)[0]['Endpoints']['docker']['Host']
        if os.environ.get('DOCKER_HOST') and not os.environ.get('DOCKER_CONTEXT'):
            endpoint = os.environ['DOCKER_HOST']
        if not endpoint.startswith('unix://'):
            raise ValueError('Remote Docker contexts are unsupported.')
        docker_env = dict(os.environ, DOCKER_HOST=endpoint)
        docker_env.pop('DOCKER_CONTEXT', None)
        image = command('image', ['docker', 'image', 'inspect', IMAGE], timeout=20, check=False)
        if image.returncode:
            receipt['skipped'] = 'Existing MariaDB image or local Docker unavailable; no pull attempted.'; return receipt
        php = os.environ.get('CONVERSION_MARIADB_PHP')
        source = os.environ.get('PAYMENTER_TEST_APP')
        if not php or not source:
            raise ValueError('Set CONVERSION_MARIADB_PHP and PAYMENTER_TEST_APP.')
        command('php-driver', [php, '-r', 'if(!in_array("mysql",PDO::getAvailableDrivers(),true)){exit(1);}'])
        if not shutil.which('unshare'):
            raise ValueError('Network namespace isolation requires unshare.')
        command('netns', ['unshare', '--user', '--map-root-user', '--net', 'true'])
        memory_gate()
        original = native.verify(source)[1]
        app = scratch/'app'; native.prepare(source, app)
        sock = scratch/'sock'; sock.mkdir(mode=0o777); sock.chmod(0o777)
        password = secrets.token_hex(24)
        docker_env.update(MARIADB_ROOT_PASSWORD=password, MYSQL_PWD=password)
        image_id = json.loads(image.stdout)[0]['Id']
        receipt.update(image_id=image_id, container=container, owner=owner)
        attempted = True
        start = command('start', ['docker', 'run', '--pull=never', '--rm', '-d', '--name', container,
            '--label', 'domains-schema-rehearsal='+owner, '--network', 'none', '--memory=512m', '--cpus=1',
            '--tmpfs', '/var/lib/mysql', '-v', str(sock)+':/run/mysqld', '-e', 'MARIADB_ROOT_PASSWORD', image_id,
            '--skip-networking', '--socket=/run/mysqld/mysqld.sock', '--innodb-buffer-pool-size=64M'])
        container_id = start.stdout.decode().strip()
        if not re.fullmatch('[a-f0-9]{64}', container_id):
            container_id = None; raise ValueError('Owned immutable container ID unavailable.')
        socket = sock/'mysqld.sock'
        sql = ['docker', 'exec', '-i', '-e', 'MYSQL_PWD', container_id, 'mariadb',
               '--user=root', '--socket=/run/mysqld/mysqld.sock']
        for _ in range(90):
            if socket.exists():
                ready = command('ready', sql+['--batch', '--skip-column-names', '-e', 'SELECT 1'], check=False, timeout=10)
                if ready.returncode == 0 and ready.stdout.strip() == b'1':
                    break
            time.sleep(1)
        else:
            raise RuntimeError('MariaDB socket not ready.')
        for index, prefix in enumerate(['', 'p']):
            database = 'schema_proof_'+str(index)
            command('database', sql, data=('CREATE DATABASE '+database+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;').encode())
            env = php_environment(app, socket, database, password, prefix)
            php_args = ['unshare', '--user', '--map-root-user', '--net', php, '-d', 'allow_url_fopen=0',
                '-d', 'zend.exception_ignore_args=1', '-d',
                'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_connect,socket_sendto,mail']
            if exercise is None:
                result = command('lifecycle-'+str(index), php_args+[str(ROOT/'tests/Support/mariadb-schema.php')], env=env, timeout=180)
                proof = json.loads(result.stdout)
            else:
                proof = exercise(command, php_args, env, scratch)
            if not proof['complete'] or proof['prefix'] != prefix:
                raise AssertionError('Lifecycle did not complete for the intended prefix.')
            receipt['runs'].append(proof)
        receipt['complete'] = True
        return receipt
    finally:
        for sig in previous:
            signal.signal(sig, signal.SIG_IGN)
        try:
            if attempted and container_id is None:
                probe = subprocess.run(['docker', 'container', 'inspect', container], env=docker_env, capture_output=True, timeout=20)
                if probe.returncode == 0:
                    info = json.loads(probe.stdout)[0]
                    if info.get('Config', {}).get('Labels', {}).get('domains-schema-rehearsal') == owner:
                        container_id = info['Id']
            if container_id:
                result = subprocess.run(['docker', 'stop', '-t', '5', container_id], env=docker_env, capture_output=True, timeout=30)
                for _ in range(25):
                    removed = subprocess.run(['docker', 'container', 'inspect', container_id], env=docker_env, capture_output=True, timeout=20)
                    if removed.returncode != 0: break
                    time.sleep(0.2)
                receipt['container_removed'] = result.returncode == 0 and removed.returncode != 0
                if not receipt['container_removed']:
                    receipt['complete'] = False
            if original is not None:
                receipt['dependency_unchanged'] = native.verify(source)[1] == original
                if not receipt['dependency_unchanged']:
                    receipt['complete'] = False
        except Exception as error:
            receipt.update(complete=False, cleanup_error=type(error).__name__)
        finally:
            shutil.rmtree(scratch/'app', ignore_errors=True)
            receipt['seconds'] = time.monotonic()-began
            (scratch/'receipt.json').write_text(json.dumps(receipt, indent=2)+'\n')
            print(json.dumps({'complete': receipt['complete'], 'receipt': str(scratch/'receipt.json'), 'seconds': receipt['seconds'],
                              'skipped': receipt.get('skipped')}), flush=True)
            for sig, handler in previous.items():
                signal.signal(sig, handler)


if __name__ == '__main__':
    try:
        result = run()
        raise SystemExit(77 if result.get('skipped') else (0 if result['complete'] else 1))
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
