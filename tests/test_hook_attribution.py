"""Fresh native provider boot and paid-operation recovery. Synthetic offline exchanges only."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def main():
    fixture = Path(os.environ['PAYMENTER_TEST_FIXTURE'])
    with tempfile.TemporaryDirectory(prefix='domains-hook-', dir=fixture.parent) as temporary:
        root = Path(temporary)
        app = root/'paymenter'
        shutil.copytree(fixture, app, ignore=shutil.ignore_patterns('*.sqlite', 'domains-editor-routes.php'))
        installed = app/'extensions/Servers/Domains'
        if installed.is_symlink():
            installed.unlink()
        elif installed.exists():
            shutil.rmtree(installed)
        installed.parent.mkdir(parents=True, exist_ok=True)
        installed.symlink_to(ROOT/'Domains', target_is_directory=True)
        for path in (app/'bootstrap/cache').glob('*.php'):
            path.unlink()
        env = dict(os.environ, PAYMENTER_TEST_FIXTURE=str(app), HOOK_ROOT=str(root))

        def phase(name, mode='console', database='seed.sqlite'):
            command = [str(ROOT/'scripts/php-test'), 'tests/Support/hook-attribution.php']
            result = subprocess.run(command, cwd=ROOT, env=dict(env, HOOK_PHASE=name, HOOK_MODE=mode,
                                    HOOK_DATABASE=str(root/database)), capture_output=True, text=True, timeout=90)
            print(result.stdout, end='', flush=True)
            if result.returncode:
                raise AssertionError(f'{name}/{mode} failed ({result.returncode}): {result.stderr}')

        phase('seed')
        phase('rebuilt')
        phase('duplicate')
        for mode in ('console', 'http'):
            phase('healthy', mode)
            database = mode+'.sqlite'
            shutil.copyfile(root/'seed.sqlite', root/database)
            phase('missing', mode, database)
            phase('recovery', mode, database)
        print(json.dumps({'hook_attribution': True, 'fresh_console_and_http_boot': True,
                          'paid_registration_and_transfer_recovered': True, 'real_provider_calls': 0}))


if __name__ == '__main__':
    main()
