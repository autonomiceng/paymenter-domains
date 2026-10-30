# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Shared installed-package scenarios; direct execution covers default-held registration."""
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
# older version is implied. Source is pinned, with explicit unreleased schema
# fixture adjustments below; superseded declarations have no upgrade path.
PREVIOUS = 'e7881cf404e934e1cc84931ab9036e84804b8b61'


def previous_build(root, revision=PREVIOUS, *, legacy=True):
    previous_source = root/'previous-source'; previous_source.mkdir()
    archive = subprocess.check_output(['git', 'archive', revision, 'Domains'], cwd=ROOT)
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
    if legacy:
        # The unreleased observation creation baseline now includes this index.
        # Keep the historical runtime/additive migrations, without claiming an
        # upgrade path from its superseded synthetic index set.
        observation = previous_source/'Domains/Services/ObservationSchema.php'
        old = observation.read_text()
        anchor = "                $t->unsignedBigInteger('due_at')->index(); $t->unsignedInteger('tries')->default(0);\n"
        assert old.count(anchor) == 1, 'Pinned observation fixture changed'
        observation.write_text(old.replace(anchor, anchor + "                $t->index(['server_id','account_fingerprint','due_at'], $t->getTable().'_account_due_index');\n"))
    return build.build(previous_source/'Domains', root/'previous')


def refuse_unsafe_archives(root, current):
    # Refuse before native upload, which executes extension PHP and has no
    # knowledge of our release manifest. These checks never consume an upload.
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


def main(scenario):
    if scenario not in ('held', 'fresh', 'upgrade', 'previous', 'enom-live', 'acquisition', 'enom-exchange', 'enom-test-registration', 'enom-test-renewal', 'enom-test-management', 'enom-test-transfer', 'namesilo-registration', 'namesilo-renewal', 'namesilo-enrollment'):
        raise ValueError('Unknown installed package scenario')
    began = time.monotonic()
    # Keep scenario limits above the measured identity and management costs.
    scenario_timeout = 110 if scenario == 'held' else 90 if scenario == 'enom-test-management' else None
    scenario_deadline = began + scenario_timeout if scenario_timeout is not None else None
    fixture = Path(os.environ['PAYMENTER_TEST_FIXTURE'])
    if not (fixture/'.domains-fixture.json').is_file():
        raise ValueError('Runner-created pristine copy required')
    memory_wait_started = time.monotonic()
    for attempt in range(7):
        available = int(next(line.split()[1] for line in Path('/proc/meminfo').read_text().splitlines() if line.startswith('MemAvailable:')))
        if available >= 1_572_864:
            break
        if attempt == 6:
            raise RuntimeError('At least 1.5 GiB MemAvailable is required for installed package acceptance')
        time.sleep(10)
    print(json.dumps({'timing': 'memory_wait', 'scenario': scenario,
                      'seconds': round(time.monotonic() - memory_wait_started, 3)}), flush=True)
    print(f'Installed fixture MemAvailable: {available} kB', flush=True)
    with tempfile.TemporaryDirectory(prefix=f'domains-installed-{scenario}-', dir=fixture.parent) as temporary:
        setup_started = time.monotonic()
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
        print(json.dumps({'timing': 'fixture_setup', 'scenario': scenario,
                          'seconds': round(time.monotonic() - setup_started, 3)}), flush=True)
        evidence = {'installed_package': True, 'scenario': scenario,
                    'zip_sha256': current['archive_sha256'], 'real_provider_calls': 0}
        previous = None
        baseline_migrations = None
        if scenario == 'held':
            refuse_unsafe_archives(root, current)
            evidence['unsafe_archives_refused'] = 3
        elif scenario == 'upgrade':
            previous = previous_build(root)
            assert previous['archive_sha256'] != current['archive_sha256'], 'Upgrade must replace different runtime bytes'
            evidence.update(previous_revision=PREVIOUS,
                            previous_fixture_adjustments=['observation_account_due_index'],
                            previous_zip_sha256=previous['archive_sha256'])
        elif scenario == 'previous':
            requested = os.environ.get('DOMAINS_UPGRADE_FROM', 'HEAD~1')
            revision = subprocess.check_output(['git', 'rev-parse', '--verify', '--end-of-options',
                                                requested+'^{commit}'], cwd=ROOT, timeout=10).decode().strip()
            previous = previous_build(root, revision, legacy=False)
            baseline_migrations = len(list((root/'previous-source/Domains/database/migrations').glob('*.php')))
            unchanged = previous['archive_sha256'] == current['archive_sha256']
            print('Runtime-selected baseline: '+revision+('; no Domains diff; still running' if unchanged else '; Domains differ'), flush=True)
            evidence.update(previous_revision=revision, previous_fixture_adjustments=[],
                            previous_zip_sha256=previous['archive_sha256'], no_domains_diff=unchanged)
        current_migrations = len(list((ROOT/'Domains/database/migrations').glob('*.php')))
        first, count = 'current', current_migrations
        if scenario in ('upgrade', 'previous'):
            first, count = 'previous', 7 if scenario == 'upgrade' else baseline_migrations
        # Every scenario owns its app copy and retained database.
        database = root/(scenario+'.sqlite'); database.touch()
        retained = root/(scenario+'-retained.json')
        migrations = root/(scenario+'-migrations.json')
        env = dict(os.environ, PAYMENTER_TEST_FIXTURE=str(app), PACKAGE_DATABASE=str(database),
                   PACKAGE_RETAINED=str(retained), PACKAGE_MIGRATION_RECEIPT=str(migrations), PACKAGE_MIGRATIONS=str(count),
                   PACKAGE_STANDING_UPGRADE='1' if scenario == 'previous' else '')
        def phase(name, package=None, worker=None):
            phase_started = time.monotonic()
            package_seconds = 0.0
            print(json.dumps({'timing': 'phase_start', 'scenario': scenario, 'phase': name}), flush=True)
            run_env = dict(env, PACKAGE_PHASE=name)
            run_env['PACKAGE_TIMINGS'] = '1'
            if worker is not None:
                run_env['PACKAGE_RACE_WORKER'] = str(worker)
            if package:
                package_started = time.monotonic()
                receipt = current if package == 'current' else previous
                original = root/package/receipt['archive']
                build.verify(original, receipt)
                upload = root/'upload'/receipt['archive']; upload.parent.mkdir(exist_ok=True)
                shutil.copyfile(original, upload)
                build.verify(upload, receipt)
                run_env['PACKAGE_ZIP'] = str(upload)
                package_seconds = time.monotonic() - package_started
            phase_limit = 120 if name in ['enom-test-renewal', 'enom-live'] else 45
            timeout = phase_limit
            try:
                if scenario_deadline is not None:
                    remaining = scenario_deadline - time.monotonic()
                    if remaining <= 0:
                        raise TimeoutError(f'Installed package {scenario} phase {name} exhausted the {scenario_timeout}-second scenario deadline')
                    timeout = min(phase_limit, remaining)
                subprocess.run([str(ROOT/'scripts/php-test'), str(ROOT/'tests/package-lifecycle.php')], cwd=ROOT, env=run_env, check=True, timeout=timeout)
            except subprocess.TimeoutExpired as error:
                if scenario_deadline is not None and time.monotonic() >= scenario_deadline:
                    raise TimeoutError(f'Installed package {scenario} phase {name} exhausted the {scenario_timeout}-second scenario deadline') from error
                raise TimeoutError(f'Installed package {scenario} phase {name} exceeded {timeout} seconds') from error
            finally:
                print(json.dumps({'timing': 'phase_finish', 'scenario': scenario, 'phase': name,
                                  'seconds': round(time.monotonic() - phase_started, 3),
                                  'package_seconds': round(package_seconds, 3)}), flush=True)
        if scenario == 'enom-test-management':
            phase('install-enom-test-management', first)
        else:
            phase('install', first)
        if scenario == 'held':
            phase('held')
            phase('identity')
            phase('catalogue')
            phase('upload', 'current')
            phase('catalogue-retained')
            evidence['test_catalogue_fresh_reinstall'] = True
            evidence['fresh_installed_registration_held'] = True
        elif scenario == 'enom-live':
            phase('enom-live')
            evidence['live_registration_renewal_and_refusals_offline'] = True
        elif scenario == 'enom-test-transfer':
            phase(scenario)
            phase('enom-test-transfer-restart')
            evidence['test_transfer_paid_code_cap_history_restart_timeout'] = True
        elif scenario == 'enom-test-management':
            phase('enom-test-management-restart')
            evidence['test_management_readback_and_restart'] = True
        elif scenario == 'enom-test-renewal':
            phase(scenario)
            phase('enom-test-renewal-restart')
            from concurrent.futures import ThreadPoolExecutor
            for race in ('issue', 'admit'):
                with ThreadPoolExecutor(max_workers=2) as workers:
                    list(workers.map(lambda worker: phase('enom-test-renewal-race-'+race, worker=worker), (1, 2)))
            phase('enom-test-renewal-race-verify')
            evidence['overlapping_issuance_and_admission_workers'] = True
            evidence['test_renewal_native_payment_charge_expiry'] = True
        elif scenario == 'enom-test-registration':
            phase('enom-test-registration')
            from concurrent.futures import ThreadPoolExecutor
            with ThreadPoolExecutor(max_workers=2) as workers:
                list(workers.map(lambda worker: phase('enom-test-calls', worker=worker), (1, 2)))
            totals = [json.loads(Path(str(retained)+f'.test-calls-{worker}.json').read_text()) for worker in (1, 2)]
            assert sorted(row['admitted'] for row in totals) == [3, 4], 'Both workers reserve under contention'
            for row in totals:
                assert row['admitted'] + row['refused'] == 20, 'Every worker accounts for all decisions'
                assert row['refused'] == 20 - row['admitted'], 'Per-worker refused total'
                assert row['barriers'] == 20, 'Every reservation reached the shared decision barrier'
            evidence['counter_worker_totals'] = totals
            phase('enom-test-calls')
            phase('enom-test-call-count')
            shutil.rmtree(installed)
            phase('reinstall', 'current')
            phase('enom-test-call-count')
            phase('enom-test-calls')
            evidence['test_registration_and_cap_survive_restart_and_reinstall'] = True
        elif scenario == 'enom-exchange':
            phase('exchange-contracts')
            phase('enom-exchange')
            evidence['bounded_enom_exchange'] = True
        elif scenario == 'namesilo-enrollment':
            phase(scenario)
            evidence['namesilo_ote_enrollment'] = True
        elif scenario.startswith('namesilo-'):
            phase(scenario)
            evidence['namesilo_frozen_nexus' if scenario.endswith('registration') else 'namesilo_renewal_readback'] = True
        elif scenario == 'acquisition':
            phase('acquisition')
            evidence['typed_acquisition_routing'] = True
        else:
            phase('seed')
            if scenario == 'previous':
                shutil.rmtree(installed)
                phase('reinstall', 'current')
            else:
                phase('upload', 'current')
            env['PACKAGE_MIGRATIONS'] = str(current_migrations)
            phase('verify')
            if scenario == 'previous':
                evidence.update(upgrade=True, native_install_retained=True, retained_rows=True, migration_history_ordered=True,
                                seconds=round(time.monotonic()-began, 3))
                print(json.dumps(evidence), flush=True)
                return
            phase('watch-seed')
            phase('verify')
            evidence.update(watch_adoption_retained=True, activation_default_off=True)
            # The native upgrade is now running current classes and migration history.
            phase('template-defaults')
            phase('upload', 'current')
            phase('verify')
            phase('server-delete')
            phase('verify')
            # Model removal of runtime files / database restoration in this private copy.
            shutil.rmtree(installed)
            phase('reinstall', 'current')
            phase('verify')
            evidence.update(same_zip_reinstall=True, native_delete_refused=True,
                            retained_install_branch=True, uninstall_hook_retained=True)
            evidence['fresh_install' if scenario == 'fresh' else 'upgrade'] = True
        print(json.dumps(evidence))
        print(json.dumps({'timing': 'scenario', 'scenario': scenario,
                          'seconds': round(time.monotonic() - began, 3)}), flush=True)


if __name__ == '__main__':
    main('held')
