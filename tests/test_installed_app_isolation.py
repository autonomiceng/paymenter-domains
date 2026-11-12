# MIT, Copyright (c) 2026 Autonomic Engineering.
"""A reused worker must never supply a class missing from an installed app."""
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
import installed_fixture


def main():
    fixture = Path(os.environ['PAYMENTER_TEST_FIXTURE'])
    linked = fixture / 'extensions/Servers/Domains'
    linked.parent.mkdir(parents=True, exist_ok=True)
    previous = linked.readlink() if linked.is_symlink() else None
    if linked.exists() and previous is None:
        raise AssertionError('Worker extension must be absent or a source link')
    with tempfile.TemporaryDirectory(prefix='worker-source-', dir=fixture.parent) as temporary:
        source = Path(temporary) / 'Domains'
        shutil.copytree(ROOT / 'Domains', source)
        (source / 'WorkerOnlyFixture.php').write_text(
            '<?php namespace Paymenter\\Extensions\\Servers\\Domains; final class WorkerOnlyFixture {}'
        )
        if previous is not None:
            linked.unlink()
        linked.symlink_to(source, target_is_directory=True)
        try:
            with installed_fixture.application(fixture, 'worker-isolation') as (root, app):
                database = root / 'isolation.sqlite'
                database.touch()
                env = dict(os.environ, PAYMENTER_TEST_FIXTURE=str(app),
                           PACKAGE_DATABASE=str(database), PACKAGE_PHASE='worker-isolation')
                probe = root / 'probe.php'
                probe.write_text(
                    '<?php require ' + repr(str(ROOT / 'tests/Support/installed-app.php')) + ';\n'
                    "expect(!class_exists('Paymenter\\\\Extensions\\\\Servers\\\\Domains\\\\WorkerOnlyFixture'), "
                    "'A class absent from the installed app must not load from the worker source');\n"
                    '$nativeSuccessful = true;\n'
                )
                subprocess.run([str(ROOT / 'scripts/php-test'), str(probe)],
                               cwd=ROOT, env=env, check=True, timeout=45)
        finally:
            linked.unlink()
            if previous is not None:
                linked.symlink_to(previous, target_is_directory=True)
    print('Installed app refuses classes available only through the worker source link.')


if __name__ == '__main__':
    main()
