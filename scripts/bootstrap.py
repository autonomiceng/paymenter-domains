#!/usr/bin/env python3
"""Prepare hash-verified PHP 8.4.26; network downloads require --download."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import tempfile
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
CACHE = ROOT / '.cache/php-runtime'
LOCK = ROOT / 'scripts/php-runtime-packages.json'


def prepare(download=False):
    if platform.system() != 'Linux' or platform.machine() != 'x86_64':
        raise RuntimeError('The pinned runner requires Linux x86_64.')
    CACHE.mkdir(parents=True, exist_ok=True)
    source = os.environ.get('PHP_RUNTIME_ARCHIVE_CACHE')
    archives = []
    for package in json.loads(LOCK.read_text()):
        archive = CACHE / package['url'].rsplit('/', 1)[1]
        if not archive.exists() and source and (Path(source) / archive.name).is_file():
            shutil.copyfile(Path(source) / archive.name, archive)
        if not archive.exists():
            if not download:
                raise RuntimeError('PHP archive missing. Run scripts/bootstrap.py --download or set PHP_RUNTIME_ARCHIVE_CACHE.')
            with urllib.request.urlopen(package['url'], timeout=60) as response:
                payload = response.read(16 * 1024 * 1024 + 1)
            if len(payload) > 16 * 1024 * 1024:
                raise RuntimeError('Runtime archive exceeds limit.')
            if hashlib.sha256(payload).hexdigest() != package['sha256']:
                raise RuntimeError('Downloaded PHP archive checksum mismatch.')
            archive.write_bytes(payload)
        if hashlib.sha256(archive.read_bytes()).hexdigest() != package['sha256']:
            raise RuntimeError('Cached PHP archive checksum mismatch; remove the archive and bootstrap again.')
        archives.append(archive)
    # Re-extract verified archives rather than trusting an existing executable or wrapper.
    runtime = Path(tempfile.mkdtemp(prefix='runtime-', dir=CACHE))
    try:
        for archive in archives:
            subprocess.run(['dpkg-deb', '-x', str(archive), str(runtime)], check=True,
                           stdout=subprocess.DEVNULL)
        binary = runtime / 'usr/bin/php8.4'
        extension = runtime / 'usr/lib/php/20240924/curl.so'
        env = dict(os.environ, PHP_INI_SCAN_DIR='', PHPRC='')
        version = subprocess.check_output([str(binary), '-n', '-r', 'echo PHP_VERSION;'], env=env, text=True)
        if version != '8.4.26' or not extension.is_file():
            raise RuntimeError('Pinned PHP runtime verification failed.')
        return runtime, binary, extension
    except BaseException:
        shutil.rmtree(runtime)
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--download', action='store_true')
    runtime, _, _ = prepare(parser.parse_args().download)
    shutil.rmtree(runtime)
    print('PHP 8.4.26 archives verified and cached; offline runner ready.')
