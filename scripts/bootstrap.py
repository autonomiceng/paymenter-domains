#!/usr/bin/env python3
"""Prepare the PHP 8.4 suite image. Network access requires --download."""
import argparse
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

import php_suite


def prepare(*, native=False):
    """Give host-side renderers and contract checks their own disposable copy."""
    runtime = Path(tempfile.mkdtemp(prefix='php-runtime-'))
    try:
        if php_suite.RUNTIME.is_dir():
            shutil.copytree(php_suite.RUNTIME, runtime, dirs_exist_ok=True, symlinks=True)
        else:
            with php_suite.image_container(php_suite.image_id()) as cid:
                subprocess.run(['docker', 'cp', f'{cid}:{php_suite.RUNTIME}/.', str(runtime)], check=True)
        binary = runtime / 'usr/bin/php8.4'
        extension = runtime / 'usr/lib/php/20240924/curl.so'
        env = dict(os.environ, PHP_INI_SCAN_DIR='', PHPRC='')
        if native:
            gd = subprocess.run([str(binary), '-n', '-d', 'extension='+str(extension.parent/'gd.so'),
                                 '-r', 'exit(extension_loaded("gd") ? 0 : 1);'],
                                env=env, capture_output=True, text=True, timeout=30)
            if gd.returncode:
                raise RuntimeError('Native PHP GD failed to load. The host needs libgd3 (libgd.so.3) '
                                   'and its dependencies.\n'+(gd.stderr+gd.stdout).strip())
        return runtime, binary, extension
    except BaseException:
        shutil.rmtree(runtime)
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--download', action='store_true')
    parser.add_argument('--native', action='store_true', help='Accepted for compatibility. The image always includes native modules.')
    args = parser.parse_args()
    if args.download:
        php_suite.build()
    try:
        image = php_suite.image_id()
    except subprocess.CalledProcessError:
        raise SystemExit('PHP suite image unavailable. Run scripts/bootstrap.py --download to prepare it.') from None
    print(f'PHP 8.4 suite image ready ({image}).')
