"""Private worker state with shared read-only dependencies for the offline runner."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from concurrent.futures import ThreadPoolExecutor
from functools import partial
import hashlib
import os
from pathlib import Path
import shutil
import native


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
        def copy_file(source, destination):
            # App files can be changed by upgrade checks. Only vendor bytes are
            # immutable; directory entries and all generated state stay private.
            if Path(source).is_relative_to(fixture/'vendor'):
                return os.link(source, destination)
            return shutil.copy2(source, destination)
        shutil.copytree(fixture, copy, copy_function=copy_file)
        native.verify(copy, fixture_snapshot=fixture_snapshot)
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
    if fixture:
        # This is the run-owned export, never the pristine dependency checkout.
        # Freeze it before linking so no worker can write shared vendor bytes.
        for name in fixture_snapshot:
            if name.startswith('vendor/'):
                path = fixture/name
                path.chmod(path.stat().st_mode & ~0o222)
    runtime_snapshot = runtime_hashes(runtime) if runtime else None
    create = partial(environment, env=env, output=output, runtime=runtime, fixture=fixture,
                     fixture_snapshot=fixture_snapshot, runtime_snapshot=runtime_snapshot)
    # Commands start only after every worker's files have been verified.
    with ThreadPoolExecutor(max_workers=jobs) as pool:
        workers = list(pool.map(create, range(jobs)))
    return workers, fixture_snapshot, runtime_snapshot


def verify_worker(env, *, fixture_snapshot, runtime_snapshot):
    if fixture_snapshot is not None:
        native.verify(env['PAYMENTER_TEST_FIXTURE'], fixture_snapshot=fixture_snapshot)
    if runtime_snapshot is not None:
        runtime = Path(env['PAYMENTER_TEST_PHP']).parents[2]
        if runtime_hashes(runtime) != runtime_snapshot:
            raise RuntimeError('Worker PHP runtime changed.')


def verify(workers, fixture_snapshot, runtime_snapshot):
    check = partial(verify_worker, fixture_snapshot=fixture_snapshot, runtime_snapshot=runtime_snapshot)
    with ThreadPoolExecutor(max_workers=max(1, len(workers))) as pool:
        list(pool.map(check, workers))
