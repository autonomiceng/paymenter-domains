"""Private, reusable worker environments for the offline runner."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from functools import partial
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import native
import installed_fixture


def runtime_hashes(root):
    # Verified Debian archives contain relative library/documentation links.
    return {str(path.relative_to(root)): ('link', os.readlink(path)) if path.is_symlink()
            else ('file', hashlib.sha256(path.read_bytes()).hexdigest())
            for path in sorted(root.rglob('*')) if path.is_symlink() or path.is_file()}


def environment(index, *, env, output, runtime, fixture, fixture_snapshot, runtime_snapshot):
    directory = output/'workers'/str(index)
    directory.mkdir(parents=True)
    temporary = directory/'tmp'
    temporary.mkdir()
    child = dict(env, TMPDIR=str(temporary), TMP=str(temporary), TEMP=str(temporary),
                 PYTHONDONTWRITEBYTECODE='1')
    if fixture:
        copy = directory/'paymenter'
        vendor = (fixture/'vendor').resolve(strict=True)
        shutil.copytree(fixture, copy, ignore=lambda directory, names: {'vendor'} if Path(directory) == fixture else set())
        (copy/'vendor').symlink_to(vendor, target_is_directory=True)
        native.verify(copy, fixture_snapshot=fixture_snapshot, shared_vendor=vendor)
        child['PAYMENTER_TEST_SHARED_VENDOR'] = str(vendor)
        child['PAYMENTER_TEST_FIXTURE'] = str(copy)
    if runtime:
        copy = directory/'runtime'
        shutil.copytree(runtime, copy, symlinks=True)
        if runtime_hashes(copy) != runtime_snapshot:
            raise RuntimeError('Worker PHP runtime copy changed.')
        for name in ['PAYMENTER_TEST_PHP', 'PAYMENTER_TEST_CURL_EXTENSION',
                     'PAYMENTER_TEST_NATIVE_EXTENSIONS', 'LD_LIBRARY_PATH']:
            if name in child:
                child[name] = str(copy/Path(child[name]).relative_to(runtime))
    return child


def environments(env, output, jobs, runtime=None):
    output = output.resolve()
    fixture = Path(env['PAYMENTER_TEST_FIXTURE']) if env.get('PAYMENTER_TEST_FIXTURE') else None
    fixture_snapshot = native.hashes(fixture) if fixture else None
    runtime_snapshot = runtime_hashes(runtime) if runtime else None
    create = partial(environment, env=env, output=output, runtime=runtime, fixture=fixture,
                     fixture_snapshot=fixture_snapshot, runtime_snapshot=runtime_snapshot)
    # Commands start only after every private copy has been verified.
    with ThreadPoolExecutor(max_workers=jobs) as pool:
        workers = list(pool.map(create, range(jobs)))
    return workers, fixture_snapshot, runtime_snapshot


def verify_worker(env, *, fixture_snapshot, runtime_snapshot):
    if fixture_snapshot is not None:
        native.verify(env['PAYMENTER_TEST_FIXTURE'], fixture_snapshot=fixture_snapshot,
                      shared_vendor=env.get('PAYMENTER_TEST_SHARED_VENDOR'), force_vendor=True)
    if runtime_snapshot is not None:
        runtime = Path(env['PAYMENTER_TEST_PHP']).parents[2]
        if runtime_hashes(runtime) != runtime_snapshot:
            raise RuntimeError('Worker PHP runtime changed.')


def verify(workers, fixture_snapshot, runtime_snapshot):
    check = partial(verify_worker, fixture_snapshot=fixture_snapshot, runtime_snapshot=runtime_snapshot)
    with ThreadPoolExecutor(max_workers=max(1, len(workers))) as pool:
        list(pool.map(check, workers))


_COMMAND_SNAPSHOTS = {}


@contextmanager
def command_environment(env, *, fixture_snapshot=None):
    """Keep generated app files private to a case; reuse only verified vendor files."""
    if not env or not env.get('PAYMENTER_TEST_FIXTURE'):
        yield env
        return
    fixture = Path(env['PAYMENTER_TEST_FIXTURE'])
    vendor = (fixture/'vendor').resolve(strict=True)
    if fixture_snapshot is not None:
        key = (str(fixture), id(fixture_snapshot))
        cached = _COMMAND_SNAPSHOTS.get(key)
        if cached is None:
            original = fixture_snapshot
            fixture_snapshot = {name: digest for name, digest in original.items() if name.startswith('vendor/')}
            for entry in fixture.iterdir():
                if entry.name == 'vendor':
                    continue
                if entry.is_dir():
                    fixture_snapshot.update({entry.name+'/'+name: digest for name, digest in native.hashes(entry).items()})
                elif entry.is_file():
                    fixture_snapshot[entry.name] = hashlib.sha256(entry.read_bytes()).hexdigest()
            cached = _COMMAND_SNAPSHOTS[key] = (original, fixture_snapshot)
        fixture_snapshot = cached[1]
    with installed_fixture.application(fixture, 'case', verify_vendor=fixture_snapshot is None) as (root, app):
        temporary = root/'tmp'; temporary.mkdir()
        child = dict(env, PAYMENTER_TEST_FIXTURE=str(app), TMPDIR=str(temporary),
                     TMP=str(temporary), TEMP=str(temporary))
        try:
            yield child
        finally:
            if fixture_snapshot is not None:
                native.verify(app, fixture_snapshot=fixture_snapshot, shared_vendor=vendor)


def prepare_database(env, output, root):
    snapshot = output/'core-database.sql'
    subprocess.run([str(root/'scripts/php-test'), 'tests/native-database.php'], cwd=root,
                   env=dict(env, PAYMENTER_TEST_DATABASE_EXPORT=str(snapshot)), check=True, timeout=10)
    env.update(PAYMENTER_TEST_DATABASE=str(snapshot),
               PAYMENTER_TEST_DATABASE_SHA256=hashlib.sha256(snapshot.read_bytes()).hexdigest())
    installed = output/'commerce-database.sql'
    subprocess.run([str(root/'scripts/php-test'), 'tests/commerce-database.php'], cwd=root,
                   env=dict(env, PAYMENTER_TEST_COMMERCE_EXPORT=str(installed)), check=True, timeout=10)
    env.update(PAYMENTER_TEST_COMMERCE_DATABASE=str(installed),
               PAYMENTER_TEST_COMMERCE_SHA256=hashlib.sha256(installed.read_bytes()).hexdigest())


def installed_phases(path):
    name = Path(path).name
    return name.endswith('.py') and (name.startswith('test_installed_package') or name == 'test_installed_namesilo_enrollment.py')


def phase_results(stdout):
    rows = []
    for line in stdout.decode(errors='replace').splitlines():
        try:
            row = json.loads(line)
        except ValueError:
            continue
        if isinstance(row, dict) and row.get('timing') == 'installed_case':
            rows.append(row)
    return rows


def seal_vendor(fixture, *, release=False):
    """Share dependencies read-only inside the wide runner's private mount namespace."""
    if os.environ.get('DOMAINS_TEST_OVERLAY') != '1':
        return
    vendor = str(fixture/'vendor')
    if release:
        subprocess.run(['umount', vendor], check=True)
    else:
        subprocess.run(['mount', '--bind', vendor, vendor], check=True)
        subprocess.run(['mount', '-o', 'remount,bind,ro', vendor], check=True)
