# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Installed lifecycle scenarios use active installs; retained upgrades start at the pinned checked bridge."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import time
import zipfile
import stat
import uuid

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
import build
import installed_fixture

# Previous local runtime before the additive public-suffix schema; no released
# older version is implied. Source is pinned, with explicit unreleased schema
# fixture adjustments below; superseded declarations have no upgrade path.
PREVIOUS = '89112442164558c4ab61ff32f210d1b102605112'
# Published main before C activation: a production-valid retained installation.
RETAINED = 'eab53f0e98c8c2274adc7b4d5e806aa612e0d325'


def previous_build(root, revision=PREVIOUS, *, legacy=True, name='previous'):
    previous_source = root/(name+'-source'); previous_source.mkdir()
    # A historical runtime is packaged and verified by the builder from its exact revision.
    # Stream the trusted archive so its bytes are not retained twice in the budgeted process.
    command = ['git', 'archive', revision, 'Domains', 'scripts/build.py', 'LICENSE']
    with subprocess.Popen(command, cwd=ROOT, stdout=subprocess.PIPE) as archive:
        with tarfile.open(fileobj=archive.stdout, mode='r|') as source:
            for member in source:
                path = previous_source/member.name
                if Path(member.name).is_absolute() or '..' in Path(member.name).parts:
                    raise ValueError('Unsafe previous source member')
                if member.isdir():
                    path.mkdir(parents=True, exist_ok=True)
                elif member.isfile():
                    path.parent.mkdir(parents=True, exist_ok=True)
                    with source.extractfile(member) as incoming, path.open('wb') as output:
                        shutil.copyfileobj(incoming, output)
                else:
                    raise ValueError('Unsafe previous source member')
        if archive.wait():
            raise subprocess.CalledProcessError(archive.returncode, command)
    spec = importlib.util.spec_from_file_location('pinned_package_builder', previous_source/'scripts/build.py')
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    if legacy:
        # The unreleased observation creation baseline now includes this index.
        # Keep the historical runtime/additive migrations, without claiming an
        # upgrade path from its superseded synthetic index set.
        observation = previous_source/'Domains/Services/ObservationSchema.php'
        old = observation.read_text()
        anchor = "                $t->unsignedBigInteger('due_at')->index(); $t->unsignedInteger('tries')->default(0);\n"
        assert old.count(anchor) == 1, 'Pinned observation fixture changed'
        observation.write_text(old.replace(anchor, anchor + "                $t->index(['server_id','account_fingerprint','due_at'], $t->getTable().'_account_due_index');\n"))
    return installed_fixture.package(previous_source/'Domains', root/name, builder=builder), builder


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
    scenario_timeout = (
        110
        if scenario == "held"
        else 90 if scenario == "enom-test-management" else None
    )
    scenario_deadline = (
        began + scenario_timeout if scenario_timeout is not None else None
    )
    fixture = Path(os.environ["PAYMENTER_TEST_FIXTURE"])
    if not (fixture / ".domains-fixture.json").is_file():
        raise ValueError("Runner-created pristine copy required")
    # A real native ZIP install and fresh PHP boots are essential here; a stub
    # cannot prove upload discovery, migrations, retained data or installed paths.
    # Composer inputs/builds are shared; each scenario still owns its DB and app.
    with installed_fixture.application(fixture, scenario) as (root, app):
        setup_started = time.monotonic()
        installed = app / "extensions/Servers/Domains"
        if installed.is_symlink():
            installed.unlink()
        elif installed.exists():
            shutil.rmtree(installed)
        for path in (app / "bootstrap/cache").glob("*.php"):
            path.unlink()
        current = installed_fixture.package(ROOT / "Domains", root / "current")
        print(
            json.dumps(
                {
                    "timing": "fixture_setup",
                    "scenario": scenario,
                    "seconds": round(time.monotonic() - setup_started, 3),
                }
            ),
            flush=True,
        )
        evidence = {
            "installed_package": True,
            "scenario": scenario,
            "zip_sha256": current["archive_sha256"],
            "real_provider_calls": 0,
        }
        previous = previous_builder = None
        baseline_migrations = None
        if scenario == "held":
            refuse_unsafe_archives(root, current)
            evidence['unsafe_archives_refused'] = 3
        elif scenario == 'upgrade':
            previous, previous_builder = previous_build(root, legacy=False)
            assert previous['archive_sha256'] != current['archive_sha256'], 'Upgrade must replace different runtime bytes'
            evidence.update(previous_revision=PREVIOUS,
                            previous_fixture_adjustments=[],
                            previous_zip_sha256=previous['archive_sha256'])
        elif scenario == 'previous':
            requested = os.environ.get('DOMAINS_UPGRADE_FROM', 'HEAD~1')
            revision = subprocess.check_output(['git', 'rev-parse', '--verify', '--end-of-options',
                                                requested+'^{commit}'], cwd=ROOT, timeout=10).decode().strip()
            previous, previous_builder = previous_build(root, revision, legacy=False)
            baseline_migrations = len(list((root/'previous-source/Domains/database/migrations').glob('*.php')))
            unchanged = previous['archive_sha256'] == current['archive_sha256']
            print('Runtime-selected baseline: '+revision+('; no Domains diff; still running' if unchanged else '; Domains differ'), flush=True)
            evidence.update(previous_revision=revision, previous_fixture_adjustments=[],
                            previous_zip_sha256=previous['archive_sha256'], no_domains_diff=unchanged)
        # Current financial/provider scenarios start from the normal active fresh package.
        # Retained upgrade uses the actual checked bridge snapshot in its own phase.
        retained_package = None
        evidence['catalogue_mode'] = 'checked-bridge' if scenario == 'upgrade' else 'fresh-active'
        current_migrations = len(list((ROOT/'Domains/database/migrations').glob('*.php')))
        first, count = 'current', current_migrations
        if scenario in ('upgrade', 'previous'):
            first = 'previous'
            count = len(list((root/'previous-source/Domains/database/migrations').glob('*.php')))
        # Every scenario owns its app copy and retained database.
        database = root/(scenario+'.sqlite'); database.touch()
        retained = root/(scenario+'-retained.json')
        migrations = root/(scenario+'-migrations.json')
        env = dict(os.environ, PAYMENTER_TEST_FIXTURE=str(app), PACKAGE_DATABASE=str(database),
                   PACKAGE_RETAINED=str(retained), PACKAGE_MIGRATION_RECEIPT=str(migrations), PACKAGE_MIGRATIONS=str(count),
                   PACKAGE_STANDING_UPGRADE='1' if scenario == 'previous' else '',
                   PACKAGE_CATALOGUE_MODE=evidence['catalogue_mode'])
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
                receipt = {'current': current, 'previous': previous, 'retained': retained_package}[package]
                original = root/package/receipt['archive']
                verifier = previous_builder if package == 'previous' else build
                verifier.verify(original, receipt)
                upload = root/'upload'/receipt['archive']; upload.parent.mkdir(exist_ok=True)
                shutil.copyfile(original, upload)
                verifier.verify(upload, receipt)
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
                argv = [str(ROOT/'scripts/php-test'), str(ROOT/'tests/package-lifecycle.php')]
                if os.environ.get('DOMAINS_TEST_CGROUP'):
                    resource = Path(os.environ['DOMAINS_TEST_RECEIPTS'])/('installed-'+uuid.uuid4().hex+'.json')
                    case_wall = min(30, timeout)
                    status = subprocess.run([sys.executable, os.environ['DOMAINS_TEST_BUDGET'], '--name', scenario+'/'+name,
                                             '--test-scope', '--wall', str(case_wall), '--memory', str(256*1024**2),
                                             '--receipt', str(resource), '--', *argv], cwd=ROOT, env=run_env, timeout=min(timeout, case_wall+2)).returncode
                    measured = json.loads(resource.read_text()) if resource.exists() else None
                    print(json.dumps(dict(timing='installed_case', label=scenario+'/'+name,
                                          argv=[*argv, scenario, name, str(worker)], exit=status,
                                          seconds=time.monotonic()-phase_started, resources=measured)), flush=True)
                    if status or measured is None:
                        raise subprocess.CalledProcessError(status or 1, argv)
                else:
                    subprocess.run(argv, cwd=ROOT, env=run_env, check=True, timeout=timeout)
            except subprocess.TimeoutExpired as error:
                if scenario_deadline is not None and time.monotonic() >= scenario_deadline:
                    raise TimeoutError(f'Installed package {scenario} phase {name} exhausted the {scenario_timeout}-second scenario deadline') from error
                raise TimeoutError(f'Installed package {scenario} phase {name} exceeded {timeout} seconds') from error
            finally:
                print(json.dumps({'timing': 'phase_finish', 'scenario': scenario, 'phase': name,
                                  'seconds': round(time.monotonic() - phase_started, 3),
                                  'package_seconds': round(package_seconds, 3)}), flush=True)
        if scenario == 'upgrade':
            # The bridge database contains a genuine B6 checked activation receipt,
            # captured at the pinned source. Current code never loads the converter.
            phase('install', 'previous')
            phase('bridge-seed')
            phase('bridge-upload', 'current')
            phase('bridge-verify')
            evidence.update(upgrade=True, checked_bridge_revision=PREVIOUS,
                            frozen_obligations_retained=True, converter_removed=True)
            print(json.dumps(evidence), flush=True)
            return
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
