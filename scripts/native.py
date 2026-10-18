"""Verify a pinned read-only Paymenter dependency, then copy a disposable native fixture."""
# Adapted from Enom tests/paymenter-test-app.php and tests/enom-native-app.php; MIT.
# Copyright (c) 2026 Autonomic Engineering.
import hashlib
import io
import json
from pathlib import Path
import shutil
import subprocess
import tarfile

COMMIT = 'ee729f7d99ac18942625b79f3f93d8d8b21255e8'
LOCK_SHA256 = 'fd6bdeb5557b3af0976d5688d92081abc6f7c3235806a6c4b77f4b8bca9186be'


def hashes(root):
    result = {}
    for path in sorted(root.rglob('*')):
        if path.is_symlink():
            raise ValueError('Dependency symlinks are not accepted.')
        if path.is_file():
            result[str(path.relative_to(root))] = hashlib.sha256(path.read_bytes()).hexdigest()
    return result


def git(source, *args):
    return subprocess.check_output(['git', '-C', str(source), *args], stderr=subprocess.PIPE)


def verify(source):
    source = Path(source)
    if not source.is_absolute() or source.is_symlink() or source.resolve() != source or str(source).startswith('/var/www/'):
        raise ValueError('Use a canonical pristine development dependency.')
    for forbidden in ['.env', 'bootstrap/cache/config.php']:
        if (source/forbidden).exists() or (source/forbidden).is_symlink():
            raise ValueError('Installed environment or cached configuration refused.')
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
    snapshot.update({'vendor/'+name: digest for name, digest in hashes(source/'vendor').items()})
    return members, snapshot


def prepare(source, destination):
    members, before = verify(source)
    destination.mkdir(mode=0o700)
    for name, payload in members.items():
        path = destination/name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(payload)
    shutil.copytree(Path(source)/'vendor', destination/'vendor')
    # Discard upstream cache placeholders; all generated app files belong to this fixture.
    shutil.rmtree(destination/'bootstrap/cache', ignore_errors=True)
    for area in ['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs']:
        (destination/area).mkdir(parents=True, exist_ok=True)
    (destination/'.domains-fixture.json').write_text(json.dumps({'commit': COMMIT, 'composer_lock_sha256': LOCK_SHA256}))
    if verify(source)[1] != before:
        raise RuntimeError('Dependency changed while copying the fixture.')
    return before
