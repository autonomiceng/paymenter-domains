# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Real ZIPs through Paymenter's native uploader in an isolated application."""
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import zipfile
import stat

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
import build

# Previous local runtime before the additive public-suffix schema; no released
# older version is implied. Source is pinned, with one explicit unreleased schema
# fixture adjustment below; this does not exercise upgrade from the old index set.
PREVIOUS = 'e7881cf404e934e1cc84931ab9036e84804b8b61'


def main():
    fixture = Path(os.environ['PAYMENTER_TEST_FIXTURE'])
    if not (fixture/'.domains-fixture.json').is_file():
        raise ValueError('Runner-created pristine copy required')
    for attempt in range(7):
        available = int(next(line.split()[1] for line in Path('/proc/meminfo').read_text().splitlines() if line.startswith('MemAvailable:')))
        if available >= 1_572_864:
            break
        if attempt == 6:
            raise RuntimeError('At least 1.5 GiB MemAvailable is required for installed package acceptance')
        time.sleep(10)
    print(f'Installed fixture MemAvailable: {available} kB', flush=True)
    with tempfile.TemporaryDirectory(prefix='domains-installed-', dir=fixture.parent) as temporary:
        root = Path(temporary)
        app = root/'paymenter'
        shutil.copytree(fixture, app, ignore=shutil.ignore_patterns('*.sqlite', 'domains-editor-routes.php'))
        installed = app/'extensions/Servers/Domains'
        if installed.is_symlink():
            installed.unlink()
        elif installed.exists():
            shutil.rmtree(installed)
        for path in (app/'bootstrap/cache').glob('*.php'):
            path.unlink()
        current = build.build(ROOT/'Domains', root/'current')
        previous_source = root/'previous-source'; previous_source.mkdir()
        archive = subprocess.check_output(['git', 'archive', PREVIOUS, 'Domains'], cwd=ROOT)
        with tarfile.open(fileobj=io.BytesIO(archive)) as source:
            for member in source:
                path = previous_source/member.name
                if member.isdir():
                    path.mkdir(parents=True, exist_ok=True)
                elif member.isfile() and not Path(member.name).is_absolute() and '..' not in Path(member.name).parts:
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(source.extractfile(member).read())
                else:
                    raise ValueError('Unsafe previous source member')
        # The unreleased observation creation baseline now includes this index.
        # Keep the historical runtime/additive migrations, without claiming an
        # upgrade path from its superseded synthetic index set.
        observation = previous_source/'Domains/Services/ObservationSchema.php'
        old = observation.read_text()
        anchor = "                $t->unsignedBigInteger('due_at')->index(); $t->unsignedInteger('tries')->default(0);\n"
        assert old.count(anchor) == 1, 'Pinned observation fixture changed'
        observation.write_text(old.replace(anchor, anchor + "                $t->index(['server_id','account_fingerprint','due_at'], $t->getTable().'_account_due_index');\n"))
        # Refuse before native upload, which executes extension PHP and has no
        # knowledge of our release manifest. Neither refusal consumes an upload.
        for name, reason in [(build.MANIFEST, 'Embedded manifest'), ('Domains/Mail/DomainNotice.php', 'Embedded manifest'), ('Domains/scripts/conversion/Converter.php', 'Development or private')]:
            bad = root/'refused.zip'
            with zipfile.ZipFile(root/'current'/current['archive']) as original, zipfile.ZipFile(bad, 'w') as changed:
                for entry in original.infolist():
                    payload = original.read(entry)
                    if entry.filename == name:
                        payload = b'{}' if name == build.MANIFEST else payload+b'\n// altered fixture\n'
                    changed.writestr(entry, payload)
                if name == 'Domains/scripts/conversion/Converter.php':
                    entry = zipfile.ZipInfo(name); entry.external_attr = (stat.S_IFREG | 0o644) << 16
                    changed.writestr(entry, b'<?php')
            try:
                build.verify(bad)
            except ValueError as error:
                if reason not in str(error):
                    raise AssertionError('Archive refused for the wrong reason') from error
            else:
                raise AssertionError('Unsafe archive reached native upload boundary')
        previous = build.build(previous_source/'Domains', root/'previous')
        assert previous['archive_sha256'] != current['archive_sha256'], 'Upgrade must replace different runtime bytes'
        current_migrations = len(list((ROOT/'Domains/database/migrations').glob('*.php')))
        for scenario, first, count in [('held', 'current', current_migrations), ('fresh', 'current', current_migrations), ('upgrade', 'previous', 7)]:
            # Each initial upload must take native Install, never reuse the other scenario's files.
            if installed.exists():
                shutil.rmtree(installed)
            database = root/(scenario+'.sqlite'); database.touch()
            retained = root/(scenario+'-retained.json')
            migrations = root/(scenario+'-migrations.json')
            env = dict(os.environ, PAYMENTER_TEST_FIXTURE=str(app), PACKAGE_DATABASE=str(database),
                       PACKAGE_RETAINED=str(retained), PACKAGE_MIGRATION_RECEIPT=str(migrations), PACKAGE_MIGRATIONS=str(count))
            def phase(name, package=None):
                run_env = dict(env, PACKAGE_PHASE=name)
                if package:
                    receipt = current if package == 'current' else previous
                    original = root/package/receipt['archive']
                    build.verify(original, receipt)
                    upload = root/'upload'/receipt['archive']; upload.parent.mkdir(exist_ok=True)
                    shutil.copyfile(original, upload)
                    build.verify(upload, receipt)
                    run_env['PACKAGE_ZIP'] = str(upload)
                subprocess.run([str(ROOT/'scripts/php-test'), str(ROOT/'tests/package-lifecycle.php')], cwd=ROOT, env=run_env, check=True, timeout=45)
            phase('install', first)
            if scenario == 'held':
                phase('held')
                continue
            phase('seed')
            phase('upload', 'current')
            env['PACKAGE_MIGRATIONS'] = str(current_migrations)
            phase('verify')
            # The native upgrade is now running current classes and migration history.
            phase('upload', 'current')
            phase('verify')
            phase('server-delete')
            phase('verify')
            # Model removal of runtime files / database restoration in this private copy.
            shutil.rmtree(installed)
            phase('reinstall', 'current')
            phase('verify')
        print(json.dumps({'installed_package': True, 'previous_revision': PREVIOUS,
                          'previous_fixture_adjustments': ['observation_account_due_index'],
                          'previous_zip_sha256': previous['archive_sha256'], 'zip_sha256': current['archive_sha256'],
                          'fresh_installed_registration_held': True, 'fresh_install': True, 'same_zip_reinstall': True, 'upgrade': True, 'native_delete_refused': True, 'retained_install_branch': True, 'uninstall_hook_retained': True,
                          'real_provider_calls': 0}))


if __name__ == '__main__':
    main()
