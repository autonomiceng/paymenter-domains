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
import sys
import uuid
import native
import installed_fixture
import php_suite

# Runner controls and named test options that reach commands; other DOMAINS_TEST_ values stay with the caller.
RUNNER_ENV = ['DOMAINS_TEST_CGROUP', 'DOMAINS_TEST_BUDGET', 'DOMAINS_TEST_LIMITS',
              'DOMAINS_TEST_JOBS', 'DOMAINS_TEST_FIXTURE_COSTS', 'DOMAINS_TEST_MONEY_SCALE_FULL_MATRIX']
# The MariaDB launcher reaches the caller's Docker client, and keeps its short socket scratch path, through
# these, and runs unconfined, until it gets its own isolated home.
MARIADB_HOST_PATHS = frozenset({'HOME', 'XDG_RUNTIME_DIR', 'TMPDIR'})
# Writable per-user locations and nested resource receipts, each inside the command's own root.
PRIVATE_PATHS = {'HOME': 'home', 'XDG_CONFIG_HOME': 'home/.config', 'XDG_DATA_HOME': 'home/.local/share',
                 'XDG_STATE_HOME': 'home/.local/state', 'XDG_CACHE_HOME': 'cache', 'XDG_RUNTIME_DIR': 'run',
                 'TMPDIR': 'tmp', 'TMP': 'tmp', 'TEMP': 'tmp', 'DOMAINS_TEST_RECEIPTS': 'receipts'}
CONFINE = Path(__file__).resolve().parent/'confine.py'


def runtime_hashes(root):
    # Verified Debian archives contain relative library/documentation links.
    return {str(path.relative_to(root)): ('link', os.readlink(path)) if path.is_symlink()
            else ('file', hashlib.sha256(path.read_bytes()).hexdigest())
            for path in sorted(root.rglob('*')) if path.is_symlink() or path.is_file()}


def environment(index, *, env, output, runtime, fixture, fixture_snapshot, runtime_snapshot):
    directory = output/'workers'/str(index)
    directory.mkdir(parents=True)
    child = dict(env, PYTHONDONTWRITEBYTECODE='1')
    if fixture:
        copy = directory/'paymenter'
        vendor = (fixture/'vendor').resolve(strict=True)
        shutil.copytree(fixture, copy, ignore=lambda directory, names: {'vendor'} if Path(directory) == fixture else set())
        (copy/'vendor').symlink_to(vendor, target_is_directory=True)
        native.verify(copy, fixture_snapshot=fixture_snapshot, shared_vendor=vendor)
        child['PAYMENTER_TEST_SHARED_VENDOR'] = str(vendor)
        child['PAYMENTER_TEST_FIXTURE'] = str(copy)
    if runtime:
        # The wide runner seals its verified runtime inside a private mount namespace.
        # Sharing those read-only inodes also shares executable/library pages between workers.
        # Ordinary runs retain private copies, including their mutation guards.
        shared_runtime = bool(os.statvfs(runtime).f_flag & os.ST_RDONLY)
        copy = runtime if shared_runtime else directory/'runtime'
        if not shared_runtime:
            shutil.copytree(runtime, copy, symlinks=True)
            if runtime_hashes(copy) != runtime_snapshot:
                raise RuntimeError('Worker PHP runtime copy changed.')
        for name in ['PAYMENTER_TEST_PHP', 'PAYMENTER_TEST_CURL_EXTENSION',
                     'PAYMENTER_TEST_NATIVE_EXTENSIONS', 'LD_LIBRARY_PATH']:
            if name in child:
                child[name] = str(copy/Path(child[name]).relative_to(runtime))
    if fixture and child.get('PAYMENTER_TEST_COMMERCE_DATABASE'):
        prepare_funded_registration(child, directory)
    return child


def prepared_hashes(fixture, vendor_hashes=None):
    if vendor_hashes is None:
        vendor = (fixture/'vendor').resolve(strict=True)
        vendor_hashes = {'vendor/'+name: digest for name, digest in native.admitted_vendor_hashes(vendor).items()}
    snapshot = dict(vendor_hashes)
    for entry in fixture.iterdir():
        if entry.name == 'vendor':
            continue
        if entry.is_symlink():
            raise ValueError('Fixture dependency path is linked.')
        if entry.is_dir():
            snapshot.update({entry.name+'/'+name: digest for name, digest in native.hashes(entry).items()})
        elif entry.is_file():
            snapshot[entry.name] = hashlib.sha256(entry.read_bytes()).hexdigest()
    return snapshot


def environments(env, output, jobs, runtime=None):
    output = output.resolve()
    fixture = Path(env['PAYMENTER_TEST_FIXTURE']) if env.get('PAYMENTER_TEST_FIXTURE') else None
    fixture_snapshot = prepared_hashes(fixture) if fixture else None
    runtime_snapshot = runtime_hashes(runtime) if runtime else None
    create = partial(environment, env=env, output=output, runtime=runtime, fixture=fixture,
                     fixture_snapshot=fixture_snapshot, runtime_snapshot=runtime_snapshot)
    # Commands start only after every private copy has been verified.
    with ThreadPoolExecutor(max_workers=jobs) as pool:
        workers = list(pool.map(create, range(jobs)))
    return workers, fixture_snapshot, runtime_snapshot


def record_source_check(rows, match, *, worker=None):
    unchanged, error = match
    if not unchanged:
        scope = 'suite' if worker is None else 'worker'
        print(json.dumps({'source_mismatch': scope, 'worker': worker,
                          'commands': [row['argv'] for row in rows]}), flush=True)
    for row in rows:
        if worker is not None or not unchanged:
            row.update(runtime_unchanged=unchanged, guard_complete=True,
                       runtime_error=None if unchanged else f"Source changed for worker {row['worker']}: {error or 'digest mismatch'}")


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


def pristine_fixture_check(env, original):
    fixture = Path(env['PAYMENTER_TEST_FIXTURE'])
    vendor = fixture/'vendor'
    vendor_hashes = None if original is None else {name: digest for name, digest in original.items()
                                                  if name.startswith('vendor/')}
    snapshot = prepared_hashes(fixture, vendor_hashes)
    return partial(native.verify, fixture, fixture_snapshot=snapshot,
                   shared_vendor=vendor.resolve(strict=True) if vendor.is_symlink() else None,
                   force_vendor=True)


def case_environment(env: dict[str, str], root: Path, *, keep: frozenset[str] = frozenset()) -> dict[str, str]:
    """Point home, cache, runtime and temporary paths into ROOT; KEEP names caller values that stay."""
    child = dict(env, **php_suite.FIXED_INPUTS)
    for name, relative in PRIVATE_PATHS.items():
        if name in keep and name in env:
            continue
        path = root/relative
        path.mkdir(mode=0o700, parents=True, exist_ok=True)
        child[name] = str(path)
    return child


@contextmanager
def case_root(env: dict[str, str], cases: Path, *, keep: frozenset[str] = frozenset()):
    """A private, exclusively created root in CASES for one command that does not need its own app copy."""
    cases.mkdir(mode=0o700, exist_ok=True)
    root = cases/('domains-case-'+uuid.uuid4().hex)
    root.mkdir(mode=0o700)
    try:
        yield root, case_environment(env, root, keep=keep)
    finally:
        shutil.rmtree(root)


@contextmanager
def command_environment(env: dict[str, str], cases: Path, *, keep: frozenset[str] = frozenset()):
    """A private root in CASES for each command, with its home, cache and scratch paths, and its own disposable app
    when a fixture is set. Yields the root and the command's environment; the suite verifies the shared inputs."""
    if not env.get('PAYMENTER_TEST_FIXTURE'):
        with case_root(env, cases, keep=keep) as case:
            yield case
        return
    fixture = Path(env['PAYMENTER_TEST_FIXTURE'])
    cases.mkdir(mode=0o700, exist_ok=True)
    with installed_fixture.application(fixture, 'case', verify_vendor=False, parent=cases) as (root, app):
        yield root, case_environment(dict(env, PAYMENTER_TEST_FIXTURE=str(app)), root, keep=keep)


# Runner self-tests that run the real runner, which confines its own commands; every other command loses CAP_SETFCAP.
NESTED = frozenset({'tests/test_lint.py', 'tests/test_runner.py', 'tests/test_runner_receipts.py', 'tests/test_runner_speed.py'})


def confined(argv: list[str], root: Path, *, nested: bool = False, cgroup: Path | None = None) -> list[str]:
    """ARGV run so that its case ROOT, and its own CGROUP subtree when given, are the only places it can write; only a
    NESTED command may confine another."""
    return [sys.executable, '-I', '-S', str(CONFINE), *(['--nested'] if nested else []),
            *(['--cgroup', str(cgroup)] if cgroup else []), str(root), '--', *argv]


@contextmanager
def delegated_subtree(group: Path):
    """A private cgroup subtree under GROUP for a command that limits its own phases; its phases cannot reach a
    sibling's limits. Each phase drains and removes its own group, and the subtree is removed after the command."""
    subtree = group/('phases-'+uuid.uuid4().hex)
    subtree.mkdir()
    try:
        (subtree/'cgroup.subtree_control').write_text('+memory')
        yield subtree
    finally:
        for child in sorted(subtree.glob('test-*')):
            (child/'cgroup.kill').write_text('1')
            child.rmdir()
        subtree.rmdir()


def prepare_database(env, output, root):
    snapshot = output/'core-database.sql'
    with case_root(env, output/'cases') as (_, child):
        subprocess.run([str(root/'scripts/php-test'), 'tests/native-database.php'], cwd=root,
                       env=dict(child, PAYMENTER_TEST_DATABASE_EXPORT=str(snapshot)), check=True, timeout=10)
    env.update(PAYMENTER_TEST_DATABASE=str(snapshot),
               PAYMENTER_TEST_DATABASE_SHA256=hashlib.sha256(snapshot.read_bytes()).hexdigest())
    installed = output/'commerce-database.sql'
    with case_root(env, output/'cases') as (_, child):
        subprocess.run([str(root/'scripts/php-test'), 'tests/commerce-database.php'], cwd=root,
                       env=dict(child, PAYMENTER_TEST_COMMERCE_EXPORT=str(installed)), check=True, timeout=10)
    env.update(PAYMENTER_TEST_COMMERCE_DATABASE=str(installed),
               PAYMENTER_TEST_COMMERCE_SHA256=hashlib.sha256(installed.read_bytes()).hexdigest())


def prepare_funded_registration(env, output):
    """Each worker owns one genuine checkout and restores it into private case databases."""
    root = Path(__file__).resolve().parents[1]
    operation = output/'operation-catalogue.sql'
    funded = output/'funded-registration.sql'
    with command_environment(env, output/'cases') as (_, child):
        subprocess.run([str(root/'scripts/php-test'), 'tests/Support/export-funded-registration.php'], cwd=root,
                       env=dict(child, PAYMENTER_TEST_OPERATION_EXPORT=str(operation), PAYMENTER_TEST_FUNDED_EXPORT=str(funded)), check=True, timeout=10)
    env.update(PAYMENTER_TEST_OPERATION_DATABASE=str(operation),
               PAYMENTER_TEST_OPERATION_SHA256=hashlib.sha256(operation.read_bytes()).hexdigest(),
               PAYMENTER_TEST_FUNDED_DATABASE=str(funded),
               PAYMENTER_TEST_FUNDED_SHA256=hashlib.sha256(funded.read_bytes()).hexdigest())


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


def seal_path(path, *, release=False):
    """Share verified inputs read-only inside the wide runner's private mount namespace."""
    if os.environ.get('DOMAINS_TEST_OVERLAY') != '1':
        return
    path = str(path)
    if release:
        subprocess.run(['umount', path], check=True)
    else:
        subprocess.run(['mount', '--bind', path, path], check=True)
        try:
            subprocess.run(['mount', '-o', 'remount,bind,ro', path], check=True)
        except BaseException:
            subprocess.run(['umount', path], check=True)
            raise


def seal_vendor(fixture, *, release=False):
    seal_path(fixture/'vendor', release=release)
