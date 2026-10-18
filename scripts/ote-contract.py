#!/usr/bin/env python3
"""Opt-in OTE contract harness; runtime preparation is offline, never downloads."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import argparse
import datetime
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from bootstrap import prepare
import native

ROOT = Path(__file__).resolve().parents[1]


def outcome(receipt, code, require_complete):
    evidence = None
    try:
        evidence = json.loads(receipt.read_text())
        if not isinstance(evidence, dict):
            evidence = None
    except (OSError, ValueError):
        pass
    checks = evidence.get('checks', {}) if evidence else {}
    if not isinstance(checks, dict):
        checks = {}
    if require_complete and code == 0 and checks.get('completed') is not True:
        code = 1
    uncertain = checks.get('purchase_unresolved') is True
    if require_complete and code != 0:
        attempts = evidence.get('requests', []) if evidence else []
        uncertain |= (evidence is None and receipt.exists()) or any(
            isinstance(row, dict) and row.get('command') in ('registerDomain', 'renewDomain') for row in attempts)
    result = {'exit': 3 if uncertain else code, 'receipt': str(receipt)}
    if uncertain:
        result['purchase_unresolved'] = True
        if evidence is not None:
            evidence['checks'] = dict(checks, purchase_unresolved=True)
            try:
                receipt.write_text(json.dumps(evidence, indent=2)+'\n')
            except OSError:
                pass
    return result


def verify_wrapper_guards(output):
    directory = output/'wrapper-guards'
    directory.mkdir(mode=0o700)
    receipt = directory/'receipt.json'
    receipt.write_text(json.dumps({'requests': [{'command': 'renewDomain', 'http_status': None}], 'checks': {}}))
    assert outcome(receipt, 1, True)['exit'] == 3
    assert outcome(receipt, 1, True)['purchase_unresolved'] is True
    receipt.write_text(json.dumps({'requests': [{'command': 'getAccountBalance'}], 'checks': {}}))
    assert outcome(receipt, 1, True)['exit'] == 1
    receipt.write_text(json.dumps({'checks': {'purchase_unresolved': True}}))
    assert outcome(receipt, 1, True)['exit'] == 3
    receipt.write_text('{')
    assert outcome(receipt, 1, True)['exit'] == 3


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    flags = parser.add_mutually_exclusive_group(required=True)
    flags.add_argument('--read', action='store_true')
    flags.add_argument('--write', action='store_true')
    flags.add_argument('--self-test', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    if not args.self_test and 18 <= datetime.datetime.now(datetime.timezone.utc).hour < 21:
        parser.exit(2, 'OTE time window closed.\n')
    if not args.self_test and not os.environ.get('NAMESILO_SANDBOX_API_KEY'):
        parser.exit(2, 'NAMESILO_SANDBOX_API_KEY must be supplied in the environment.\n')
    output = Path(tempfile.mkdtemp(prefix='domains-ote-'))
    runtime = fixture = snapshot = None
    source = os.environ.get('PAYMENTER_TEST_APP')
    try:
        if args.self_test:
            verify_wrapper_guards(output)
        with_native = args.write or (args.self_test and bool(source))
        runtime, binary, ext = prepare(native=with_native)
        env = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'PHP_INI_SCAN_DIR': '', 'PHPRC': '',
               'OTE_OUTPUT': str(output)}
        if with_native:
            if not source:
                raise ValueError('Pinned native dependency required for --write')
            fixture = output/'paymenter'
            snapshot = native.prepare(source, fixture)
            env.update(PAYMENTER_TEST_FIXTURE=str(fixture), LD_LIBRARY_PATH=str(runtime/'usr/lib/x86_64-linux-gnu'))
        if args.self_test and with_native:
            env['OTE_SELF_TEST_NATIVE'] = '1'
        if not args.self_test:
            env['NAMESILO_SANDBOX_API_KEY'] = os.environ['NAMESILO_SANDBOX_API_KEY']
        argv = [str(binary), '-n', '-d', 'extension='+str(ext), '-d', 'extension='+str(ext.parent/'dom.so'),
                '-d', 'allow_url_fopen=0', '-d', 'zend.exception_ignore_args=1', '-d', 'display_errors=0',
                '-d', 'log_errors=0', '-d', 'error_reporting=32767', '-d', 'ffi.enable=0']
        if with_native:
            for module in ['pdo', 'pdo_sqlite', 'sqlite3', 'mbstring', 'intl', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter']:
                argv += ['-d', 'extension='+str(ext.parent/(module+'.so'))]
        disabled = 'exec,system,passthru,shell_exec,popen,proc_open,pcntl_exec,mail,fsockopen,pfsockopen,stream_socket_client,stream_socket_server'
        if args.self_test:
            disabled += ',curl_exec,curl_multi_exec'
        argv += ['-d', 'disable_functions='+disabled, str(ROOT/'tests/contract/namesilo-ote.php'),
                 '--self-test' if args.self_test else '--write' if args.write else '--read']
        # Never relay child stdout/stderr: native errors can contain raw responses or credentials.
        result = subprocess.run(argv, cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=900)
        if args.self_test and with_native and result.returncode == 0:
            for scenario, renewals in [('malformed-registration', 0), ('renewal-transport-failure', 1)]:
                uncertain_output = output/scenario
                uncertain_output.mkdir(mode=0o700)
                uncertain_env = dict(env, OTE_OUTPUT=str(uncertain_output), OTE_SELF_TEST_SCENARIO=scenario)
                refused = subprocess.run(argv, cwd=ROOT, env=uncertain_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=900)
                evidence = json.loads((uncertain_output/'receipt.json').read_text())
                commands = [row['command'] for row in evidence['requests']]
                if refused.returncode != 3 or evidence['checks'].get('purchase_unresolved') is not True or commands.count('registerDomain') != 1 or commands.count('renewDomain') != renewals:
                    raise ValueError('Uncertain purchase guard failed')
            receipt = output/'receipt.json'
            evidence = json.loads(receipt.read_text())
            evidence['checks']['uncertain_purchase_refused'] = True
            receipt.write_text(json.dumps(evidence, indent=2)+'\n')
        if snapshot is not None and native.verify(source)[1] != snapshot:
            raise ValueError('Read-only dependency changed')
        receipt = output/'receipt.json'
        summary = outcome(receipt, result.returncode, not args.self_test)
        print(json.dumps(summary))
        return summary['exit']
    except BaseException:
        summary = outcome(output/'receipt.json', 1, not args.self_test)
        summary['error'] = 'Contract harness failed; no automatic retry.'
        print(json.dumps(summary))
        return summary['exit']
    finally:
        if runtime:
            shutil.rmtree(runtime, ignore_errors=True)
        if fixture:
            shutil.rmtree(fixture, ignore_errors=True)


if __name__ == '__main__':
    raise SystemExit(main())
