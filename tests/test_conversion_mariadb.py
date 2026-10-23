#!/usr/bin/env python3
"""Opt-in disposable MariaDB conversion rehearsal; never contacts a registrar."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import io
import hashlib
import json
import os
import re
from pathlib import Path
import secrets
import signal
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
import native

IMAGE = 'mariadb:11.4.5'
CONTAINER = 'dom-c41r-mariadb'


def memory_gate():
    for attempt in range(41):
        available = int(next(line.split()[1] for line in Path('/proc/meminfo').read_text().splitlines()
                             if line.startswith('MemAvailable:')))
        if available >= 1572864:
            return
        if attempt == 40:
            raise RuntimeError('MemAvailable remained below 1.5 GiB for ten minutes.')
        time.sleep(15)


def run():
    os.umask(0o077)
    def terminate(signum, frame):
        raise KeyboardInterrupt('Termination requested; cleaning owned container.')
    scratch_root=Path(os.environ.get('TMPDIR', ''))
    if not scratch_root.is_absolute() or not scratch_root.is_dir():
        raise ValueError('Set TMPDIR to an existing private scratch root before the rehearsal.')
    scratch = Path(tempfile.mkdtemp(prefix='c41r-', dir=scratch_root))
    receipt = {'complete': False, 'real_provider_calls': 0, 'image': IMAGE, 'runs': [], 'commands': [],
               'scope': 'Disposable real MariaDB with synthetic registrar evidence; no live conversion or locking races.'}
    container_id = None
    start_attempted = False
    owner = secrets.token_hex(16)
    source = original = None
    docker_env = None

    def command(role, argv, *, env=None, data=None, timeout=1200, check=True):
        began = time.monotonic()
        result = subprocess.run(argv, cwd=ROOT, env=env if env is not None else (docker_env if argv[0]=='docker' else None), input=data, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=timeout)
        index = len(receipt['commands'])
        (scratch/f'{index:03d}-{role}.stdout').write_bytes(result.stdout)
        (scratch/f'{index:03d}-{role}.stderr').write_bytes(result.stderr)
        receipt['commands'].append({'role': role, 'exit': result.returncode, 'elapsed_seconds': time.monotonic()-began})
        if check and result.returncode:
            raise RuntimeError(f'{role} failed; inspect private command logs.')
        return result

    previous = {sig: signal.getsignal(sig) for sig in [signal.SIGTERM, signal.SIGINT]}
    try:
        for sig in previous:
            signal.signal(sig, terminate)
        if not shutil.which('docker'):
            receipt.update(skipped='Docker is unavailable.'); return receipt
        if os.environ.get('DOCKER_HOST') and not os.environ['DOCKER_HOST'].startswith('unix://'):
            raise RuntimeError('Only a local Unix-socket Docker daemon is supported.')
        context=command('docker-context',['docker','context','inspect'],timeout=20)
        endpoint=json.loads(context.stdout)[0]['Endpoints']['docker']['Host']
        if os.environ.get('DOCKER_HOST') and not os.environ.get('DOCKER_CONTEXT'):
            endpoint=os.environ['DOCKER_HOST']
        if not endpoint.startswith('unix://'):
            raise RuntimeError('Remote Docker contexts are unsupported.')
        docker_env=dict(os.environ,DOCKER_HOST=endpoint);docker_env.pop('DOCKER_CONTEXT',None)
        image = command('image', ['docker', 'image', 'inspect', IMAGE], timeout=20, check=False)
        if image.returncode:
            receipt.update(skipped='Existing mariadb:11.4.5 image or Docker daemon is unavailable; no pull attempted.'); return receipt
        php = os.environ.get('CONVERSION_MARIADB_PHP')
        source = os.environ.get('PAYMENTER_TEST_APP')
        enom = os.environ.get('CONVERSION_ENOM_CHECKOUT')
        if not php or not source or not enom:
            raise ValueError('Set CONVERSION_MARIADB_PHP, PAYMENTER_TEST_APP and CONVERSION_ENOM_CHECKOUT.')
        command('php-driver', [php, '-r', 'if(!in_array("mysql",PDO::getAvailableDrivers(),true)){exit(1);}'])
        existing = command('container-name', ['docker', 'container', 'inspect', CONTAINER], timeout=20, check=False)
        if existing.returncode == 0:
            raise RuntimeError('Dedicated rehearsal container name already exists; refusing to touch it.')
        original = native.verify(source)[1]
        if not shutil.which('unshare') or not Path('/var/spool/cron/crontabs').is_dir():
            raise RuntimeError('Linux user/PID/mount namespaces and native cron spool are required for the isolated CLI fixture.')
        # The fixture account has no installed scheduler. Its private process/cron view excludes the desktop.
        cron=scratch/'fixture-crontabs';cron.mkdir(mode=0o755)
        entry=scratch/'namespace.sh'
        entry.write_text('set -eu\nmount --bind "$1" /var/spool/cron/crontabs\nshift\ncd /\nsleep 3600 & peer=$!\ntrap \'kill "$peer" 2>/dev/null || true\' EXIT HUP INT TERM\n"$@"\n')
        ns=['unshare','--user','--map-root-user','--mount','--net','--pid','--fork','--mount-proc','--kill-child','/bin/sh',str(entry),str(cron)]
        probe=command('namespace-preflight',ns+[php,'-r', 'require $argv[1];$p=DomainsConversion\\AppInventory::processes("/synthetic/app");DomainsConversion\\AppInventory::cron();if($p["own_peers"]<1){exit(1);}echo "native inventory verified";',str(ROOT/'scripts/conversion/AppInventory.php')])
        receipt['process_isolation']='Disposable user/PID/mount/network namespaces, native empty fixture account cron spool; no deployed-host inventory claim.'
        memory_gate()
        sock = scratch/'sock'; sock.mkdir(mode=0o777); sock.chmod(0o777)
        password = secrets.token_hex(24)
        image_id=json.loads(image.stdout)[0]['Id']
        receipt['image_id']=image_id
        docker_env.update(MARIADB_ROOT_PASSWORD=password, MYSQL_PWD=password)
        ownership = {'name': CONTAINER, 'label': owner, 'docker_host': endpoint}
        (scratch/'container.json').write_text(json.dumps(ownership, indent=2)+'\n')
        start_attempted = True
        started=command('start', ['docker', 'run', '--pull=never', '--rm', '-d', '--name', CONTAINER,
                         '--label', 'domains-conversion-rehearsal='+owner, '--network', 'none', '--memory=512m', '--cpus=1', '--tmpfs', '/var/lib/mysql',
                         '-v', str(sock)+':/run/mysqld', '-e', 'MARIADB_ROOT_PASSWORD', image_id,
                         '--skip-networking', '--socket=/run/mysqld/mysqld.sock', '--innodb-buffer-pool-size=64M'], env=docker_env)
        container_id=started.stdout.decode().strip()
        if not re.fullmatch('[a-f0-9]{64}', container_id):
            container_id=None
            raise RuntimeError('Disposable container ID unavailable.')
        ownership['id'] = container_id
        (scratch/'container.json').write_text(json.dumps(ownership, indent=2)+'\n')
        socket = sock/'mysqld.sock'
        for _ in range(90):
            if socket.exists():
                pid1=subprocess.run(['docker','exec',container_id,'cat','/proc/1/comm'],capture_output=True,timeout=10,env=docker_env)
                if pid1.returncode==0 and pid1.stdout.strip()==b'mariadbd':
                    ready=subprocess.run(['docker','exec','--user','mysql','-e','MYSQL_PWD',container_id,'mariadb','--user=root',
                        '--socket=/run/mysqld/mysqld.sock','--batch','--skip-column-names','-e','SELECT 1'],
                        capture_output=True,timeout=10,env=docker_env)
                    if ready.returncode==0 and ready.stdout.strip()==b'1':
                        break
            time.sleep(1)
        else:
            raise RuntimeError('Disposable MariaDB socket did not become ready.')
        sql = ['docker', 'exec', '-i', '-e', 'MYSQL_PWD', container_id, 'mariadb',
               '--user=root', '--socket=/run/mysqld/mysqld.sock']
        pins=json.loads((ROOT/'scripts/conversion/source.json').read_text())
        if len(pins)<2:
            raise AssertionError('Both reviewed source pins are required.')
        for pin in pins:
            revision = pin['revision']; case = scratch/revision[:7]; case.mkdir()
            app = case/'app'; native.prepare(source, app)
            export = case/'source'; export.mkdir()
            archive = command('source-export', ['git', '-C', enom, 'archive', revision, 'Enom']).stdout
            with tarfile.open(fileobj=io.BytesIO(archive)) as package:
                package.extractall(export, filter='data')
            parked = export/'Enom'
            command('database', sql, data=b'DROP DATABASE IF EXISTS c41r; CREATE DATABASE c41r CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            env = dict(os.environ, APP_ENV='testing', APP_KEY='base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ=',
                       APP_URL='http://conversion.invalid', DB_CONNECTION='mysql', DB_DATABASE='c41r',
                       DB_USERNAME='root', DB_PASSWORD=password, DB_SOCKET=str(socket), CACHE_STORE='database',
                       QUEUE_CONNECTION='database', SESSION_DRIVER='array', MAIL_MAILER='array', TELEMETRY_ENABLED='false',
                       PAYMENTER_TEST_FIXTURE=str(app), CONVERSION_SOURCE=str(parked), CONVERSION_SCRATCH=str(case),
                       CONVERSION_REVISION=revision, TMPDIR=str(case), PHPRC='', PHP_INI_SCAN_DIR='')
            php_args = [php, '-d', 'allow_url_fopen=0', '-d', 'zend.exception_ignore_args=1', '-d',
                        'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_connect,socket_sendto,mail']

            isolated_php=['unshare','--user','--map-root-user','--net']+php_args
            def phase(name):
                return command(name, isolated_php+[str(ROOT/'tests/Support/mariadb-conversion.php')], env=dict(env, CONVERSION_PHASE=name, CACHE_STORE='array' if name=='seed' else 'database'))

            phase('seed')
            command('artisan-down', isolated_php+[str(app/'artisan'), 'down', '--render=errors::503'], env=env)
            down=json.loads((app/'storage/framework/down').read_text())
            if down.get('secret') is not None or down.get('redirect') is not None or down.get('except', []) != []:
                raise AssertionError('Native maintenance enables a bypass.')
            fenced = command('http-fence', isolated_php+['-r', "$_SERVER['REQUEST_URI']='/';$_SERVER['REQUEST_METHOD']='GET';register_shutdown_function(static function(){fwrite(STDERR,(string)http_response_code());});require $argv[1];", str(app/'public/index.php')], env=env)
            if fenced.stdout != down['template'].encode() or fenced.stderr != b'503':
                raise AssertionError('Public entry point did not exit before application bootstrap.')
            operator = case/'operator.json'
            operator.write_text(json.dumps({'profile': 'app', 'stopped': ['No app web listener in disposable fixture',
                'No scheduler or queue entry points installed', 'No external triggers in isolated fixture'], 'external_schedulers_disabled': True, 'web_workers_drained_after_maintenance': True, 'standard_public_index_only': True, 'web_auto_prepend_disabled': True}))
            base = ns+php_args+[str(ROOT/'scripts/convert-enom-05.php'), '--app', str(app), '--source', str(parked),
                             '--source-revision', revision, '--evidence', str(case/'evidence.json'),
                             '--host-profile=app', '--operator-inventory', str(operator), '--quiet-seconds=60']
            def host_checks(role, result, count):
                checks=json.loads(result.stdout)['host_checks']
                if len(checks)!=count or any(c['profile']!='app' or c['quiet_seconds']!=60 for c in checks):
                    raise AssertionError('Full app re-inspections missing: '+role)
                if receipt['commands'][-1]['elapsed_seconds']<count*60:
                    raise AssertionError('Monotonic quiet windows shortened: '+role)
            staged=command('stage', base+['--stage-schema'], env=env)
            host_checks('stage', staged, 2)
            phase('refusals')
            phase('queue')
            refused = command('queue-refusal', base+['--dry-run'], env=env, check=False)
            if refused.returncode == 0 or b'Registrar queue jobs' not in refused.stderr:
                raise AssertionError('Actual app-profile CLI did not refuse queued registrar work.')
            phase('drain')
            dry = command('dry-run', base+['--dry-run'], env=env)
            host_checks('dry-run', dry, 2)
            summary = json.loads(dry.stdout)
            before = json.loads((case/'before.json').read_text())
            if summary['source_digest'] != before['source_digest'] or summary['adoption_pending'] != [22, 23, 24]:
                raise AssertionError('Real MariaDB dry-run differs from source scenario.')
            dump = command('dump', ['docker', 'exec', '-e', 'MYSQL_PWD', container_id, 'mariadb-dump',
                                   '--user=root', '--socket=/run/mysqld/mysqld.sock', '--single-transaction',
                                   '--skip-comments', '--databases', 'c41r']).stdout
            database = case/'before.sql'; database.write_bytes(dump)
            phase('backup')
            file_hashes={'app':native.hashes(app),'source':native.hashes(export)}
            (case/'backup-files.json').write_text(json.dumps(file_hashes,sort_keys=True,indent=2)+'\n')
            files = case/'files.tar'
            with tarfile.open(files, 'w') as package:
                package.add(app, arcname='app'); package.add(export, arcname='source')
            marker = case/'backup.json'
            marker.write_text(json.dumps({'revision': revision, 'source_digest': summary['source_digest'],
                'key_sha256': before['key_sha256'], 'database': {'path': str(database), 'sha256': hashlib.sha256(dump).hexdigest()},
                'files': {'path': str(files), 'sha256': hashlib.sha256(files.read_bytes()).hexdigest()}}))
            phase('audit')
            command('drop-after-audit', sql, data=b'DROP DATABASE c41r;')
            command('restore-before-app-cli', sql, data=dump)
            phase('restore')
            applied_result=command('apply', base+['--apply', '--backup-marker', str(marker)], env=env)
            host_checks('apply', applied_result, 3)
            applied = json.loads(applied_result.stdout)
            if not applied['complete'] or applied['plan_digest'] != summary['plan_digest']:
                raise AssertionError('Apply and dry-run plan mismatch.')
            readback = json.loads(phase('readback').stdout)
            command('drop-for-restore', sql, data=b'DROP DATABASE c41r;')
            command('restore-dump', sql, data=dump)
            shutil.rmtree(app);shutil.rmtree(export)
            with tarfile.open(files) as package:
                package.extractall(case, filter='data')
            for name,directory in [('app',app),('source',export)]:
                if native.hashes(directory)!=file_hashes[name]:
                    raise AssertionError('Restored file bytes differ: '+name)
            phase('restore')
            receipt['runs'].append(dict(readback, dry_apply_parity=True, actual_migrations=True,
                                        app_cli=True, quiet_seconds=60, database_files_restore=True, fixture_queue_audit=True))
            # Retain receipts/dumps/logs; discard only our disposable dependency copies and large file archive.
            shutil.rmtree(app); files.unlink()
        if [r['source_revision'] for r in receipt['runs']] != [p['revision'] for p in pins]:
            raise AssertionError('Every reviewed pin must complete.')
        receipt['dependency_unchanged'] = native.verify(source)[1] == original
        if not receipt['dependency_unchanged']:
            raise RuntimeError('Pristine dependency changed.')
        receipt['complete'] = True
        return receipt
    except BaseException as error:
        receipt['error_type'] = type(error).__name__
        receipt['error'] = str(error)
        raise
    finally:
        try:
            for sig in previous:
                signal.signal(sig, signal.SIG_IGN)
            try:
                if container_id is None and start_attempted:
                    # A timed-out start can still create our container. Never stop a reused name.
                    probe=subprocess.run(['docker', 'container', 'inspect', CONTAINER], capture_output=True, timeout=20, env=docker_env) if shutil.which('docker') else None
                    if probe is not None and probe.returncode==0:
                        info=json.loads(probe.stdout)[0]
                        if info.get('Config',{}).get('Labels',{}).get('domains-conversion-rehearsal')==owner:
                            container_id=info['Id']
                if container_id:
                    stopped = subprocess.run(['docker', 'stop', '-t', '5', container_id], capture_output=True, timeout=30, env=docker_env)
                    receipt['container_stopped'] = stopped.returncode == 0
                    if stopped.returncode:
                        receipt['complete'] = False
                if original is not None and 'dependency_unchanged' not in receipt:
                    receipt['dependency_unchanged']=native.verify(source)[1]==original
                    if not receipt['dependency_unchanged']:
                        receipt['complete']=False
            except Exception as error:
                receipt.update(complete=False, cleanup_error=type(error).__name__)
            (scratch/'receipt.json').write_text(json.dumps(receipt, indent=2)+'\n')
            print(json.dumps({'complete': receipt['complete'], 'receipt': str(scratch/'receipt.json'), 'skipped': receipt.get('skipped')}), flush=True)
        finally:
            for sig, handler in previous.items():
                signal.signal(sig, handler)


if __name__ == '__main__':
    try:
        result = run()
        raise SystemExit(77 if result.get('skipped') else (0 if result['complete'] else 1))
    except Exception as error:
        print(type(error).__name__+': '+str(error), file=sys.stderr)
        raise SystemExit(1)
