"""Verify a pinned read-only Paymenter dependency, then copy a disposable native fixture."""
# Adapted from Enom tests/paymenter-test-app.php and tests/enom-native-app.php; MIT.
# Copyright (c) 2026 Autonomic Engineering.
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import threading
from fixture_watch import UnchangedTree

COMMIT = 'ee729f7d99ac18942625b79f3f93d8d8b21255e8'
LOCK_SHA256 = 'fd6bdeb5557b3af0976d5688d92081abc6f7c3235806a6c4b77f4b8bca9186be'


def hashes(root):
    result = {}

    pending = [(root, '')] if root.is_dir() else []
    while pending:
        directory, prefix = pending.pop()
        with os.scandir(directory) as entries:
            for entry in entries:
                name = prefix + entry.name
                if entry.is_symlink():
                    raise ValueError('Dependency symlinks are not accepted.')
                if entry.is_dir(follow_symlinks=False):
                    pending.append((entry.path, name+'/'))
                elif entry.is_file(follow_symlinks=False):
                    with open(entry.path, "rb") as handle:
                        result[name] = hashlib.file_digest(handle, "sha256").hexdigest()

    return {name: result[name] for name in sorted(result, key=Path)}


# Admission lives only in this runner process, never in a caller-supplied disk cache.
# Full inventory walks still reject links/additions/removals. Linux ctime changes on
# writes even when a test restores mtime; inode/device also detect replacement.
_VENDOR_ADMISSIONS = {}
_VENDOR_WATCHES = {}
_VENDOR_LOCK = threading.RLock()


def admitted_vendor_hashes(root, *, force=False):
    with _VENDOR_LOCK:
        key = str(root)
        record = _VENDOR_WATCHES.get(key)
        if not force and record is not None and record[0].unchanged():
            return dict(record[1])
        watch = UnchangedTree(root) if record is None else record[0]
        result = _vendor_inventory(root)
        _VENDOR_WATCHES[key] = watch, result
        return result


def _vendor_inventory(root):
    root = Path(root)
    if root.is_symlink():
        raise ValueError('Dependency symlinks are not accepted.')
    previous = _VENDOR_ADMISSIONS.get(str(root), {})
    admitted = {}
    result = {}
    pending = [(root, '')] if root.is_dir() else []

    def identity(st):
        return (st.st_dev, st.st_ino, st.st_mode, st.st_size, st.st_mtime_ns, st.st_ctime_ns)

    while pending:
        directory, prefix = pending.pop()
        with os.scandir(directory) as entries:
            for entry in entries:
                name = prefix + entry.name
                if entry.is_symlink():
                    raise ValueError('Dependency symlinks are not accepted.')
                if entry.is_dir(follow_symlinks=False):
                    pending.append((entry.path, name+'/'))
                elif entry.is_file(follow_symlinks=False):
                    stamp = identity(entry.stat(follow_symlinks=False))
                    cached = previous.get(name)
                    if cached is not None and cached[0] == stamp:
                        digest = cached[1]
                    else:
                        with open(entry.path, 'rb') as handle:
                            if identity(os.fstat(handle.fileno())) != stamp:
                                raise ValueError('Dependency changed during admission.')
                            digest = hashlib.file_digest(handle, 'sha256').hexdigest()
                            if identity(os.fstat(handle.fileno())) != stamp:
                                raise ValueError('Dependency changed during admission.')
                    admitted[name] = cached if cached is not None and cached[0] == stamp else (stamp, digest)
                    result[name] = digest
    # Publish only after a complete successful inventory; no partial admission is trusted.
    _VENDOR_ADMISSIONS[str(root)] = admitted
    return result


def git(source, *args):
    return subprocess.check_output(['git', '-C', str(source), *args], stderr=subprocess.PIPE)


_FIXTURE_INDEXES = {}


def fixture_indexes(snapshot):
    # Admission snapshots are immutable for the run. Retain their identity with the indexes.
    cached = _FIXTURE_INDEXES.get(id(snapshot))
    if cached is None:
        files = {name: digest for name, digest in snapshot.items() if not name.startswith('vendor/')}
        vendor = {name[7:]: digest for name, digest in snapshot.items() if name.startswith('vendor/')}
        parents = {str(Path(name).parent) for name in files}
        ancestors = {str(parent) for name in parents for parent in (Path(name), *Path(name).parents)}
        cached = _FIXTURE_INDEXES[id(snapshot)] = (snapshot, files, vendor, ancestors)
    return cached[1:]


def verify(source, *, fixture_snapshot=None, shared_vendor=None, force_vendor=False):
    source = Path(source)
    if not source.is_absolute() or source.is_symlink() or source.resolve() != source or str(source).startswith('/var/www/'):
        raise ValueError('Use a canonical pristine development dependency.')
    for forbidden in ['.env', 'bootstrap/cache/config.php']:
        if (source/forbidden).exists() or (source/forbidden).is_symlink():
            raise ValueError('Installed environment or cached configuration refused.')
    if fixture_snapshot is not None:
        # Generated views, caches and installed extensions are fixture-local. Every
        # pristine file and the complete vendor tree must retain their original bytes.
        files, expected_vendor, relative_parents = fixture_indexes(fixture_snapshot)
        ancestors = (source/parent for parent in relative_parents)
        vendor_root = source/'vendor'
        if shared_vendor is not None:
            if not vendor_root.is_symlink() or vendor_root.resolve(strict=True) != Path(shared_vendor):
                raise ValueError('Fixture vendor link changed.')
            vendor_root = Path(shared_vendor)
        elif vendor_root.is_symlink():
            raise ValueError('Fixture dependency path is linked.')
        if any(parent.is_symlink() for parent in ancestors):
            raise ValueError('Fixture dependency path is linked.')
        for name, digest in files.items():
            path = source/name
            if path.is_symlink():
                raise ValueError('Fixture dependency path is linked.')
            if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
                raise ValueError('Fixture dependency changed: '+name)
        if admitted_vendor_hashes(vendor_root, force=force_vendor) != expected_vendor:
            raise ValueError('Fixture vendor inventory changed.')
        return {}, fixture_snapshot
    if git(source, 'rev-parse', '--show-toplevel').decode().strip() != str(source):
        raise ValueError('Dependency must be its own checkout.')
    if git(source, 'rev-parse', 'HEAD').decode().strip() != COMMIT:
        raise ValueError('Paymenter commit is not the pinned 1.5.9 baseline.')
    archive = git(source, 'archive', COMMIT)
    members = {}
    with tarfile.open(fileobj=io.BytesIO(archive)) as package:
        for entry in package:
            if entry.isdir():
                continue
            if not entry.isfile() or Path(entry.name).is_absolute() or '..' in Path(entry.name).parts:
                raise ValueError('Unexpected upstream archive member.')
            payload = package.extractfile(entry).read()
            path = source/entry.name
            if path.is_symlink() or not path.is_file():
                raise ValueError('Pristine dependency file missing or linked: '+entry.name)
            actual = path.read_bytes()
            # The reference's sole supported development edit is the exact release version marker.
            permitted = payload.replace(b"'version' => 'development'", b"'version' => '1.5.9'") if entry.name == 'config/app.php' else payload
            if actual not in [payload, permitted]:
                raise ValueError('Pristine dependency changed: '+entry.name)
            members[entry.name] = payload
    if hashlib.sha256(members['composer.lock']).hexdigest() != LOCK_SHA256:
        raise ValueError('Pinned dependency lockfile mismatch.')
    for name in ['vendor/autoload.php', 'vendor/composer/installed.json', 'bootstrap/app.php']:
        if not (source/name).is_file():
            raise ValueError('Pristine dependency with vendor is required.')
    locked = json.loads(members['composer.lock'])
    expected = {p['name']: p for p in locked['packages'] + locked['packages-dev']}
    try:
        actual = {p['name']: p for p in json.loads((source/'vendor/composer/installed.json').read_text())['packages']}
        if any(not isinstance(p.get('dist', {}), dict) or not isinstance(p['version'], str) for p in actual.values()):
            raise ValueError('Malformed installed package metadata.')
    except (KeyError, TypeError, AttributeError) as error:
        raise ValueError('Malformed installed package metadata.') from error
    if expected.keys() != actual.keys() or any(
        expected[n]['version'] != actual[n]['version'] or expected[n].get('dist', {}).get('reference') != actual[n].get('dist', {}).get('reference')
        for n in expected
    ):
        raise ValueError('Installed dependencies do not match the pinned lock.')
    snapshot = {name: hashlib.sha256((source/name).read_bytes()).hexdigest() for name in members}
    snapshot.update({'vendor/'+name: digest for name, digest in admitted_vendor_hashes(source/'vendor', force=True).items()})
    return members, snapshot


def prepare(source, destination, *, share_vendor=False):
    members, before = verify(source)
    destination.mkdir(mode=0o700)
    for name, payload in members.items():
        path = destination / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(payload)
    if share_vendor:
        # Focused serial native tests only read Composer's pinned dependencies.
        # Keep verifying the complete source/vendor inventory before and after the
        # run, but avoid another 27,000-file copy (including development tools).
        (destination / "vendor").symlink_to(
            Path(source) / "vendor", target_is_directory=True
        )
    else:
        shutil.copytree(Path(source) / "vendor", destination / "vendor")
    # Discard upstream cache placeholders; all generated app files belong to this fixture.
    shutil.rmtree(destination / "bootstrap/cache", ignore_errors=True)
    for area in [
        "bootstrap/cache",
        "storage/framework/cache",
        "storage/framework/sessions",
        "storage/framework/views",
        "storage/logs",
    ]:
        (destination / area).mkdir(parents=True, exist_ok=True)
    (destination / ".domains-fixture.json").write_text(
        json.dumps({"commit": COMMIT, "composer_lock_sha256": LOCK_SHA256})
    )
    if verify(source)[1] != before:
        raise RuntimeError('Dependency changed while copying the fixture.')
    return before
