#!/usr/bin/env python3
"""Run PHP lint and synthetic suites offline using verified, pinned PHP archives."""
# Adapted from Paymenter Enom scripts/test.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from bootstrap import prepare

ROOT = Path(__file__).resolve().parents[1]


def hashes():
    return {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in sorted((ROOT / 'NameSilo').rglob('*')) if p.is_file()}


def main():
    os.umask(0o077)
    runtime, binary, extension = prepare()
    output = Path(tempfile.mkdtemp(prefix='namesilo-tests-'))
    env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'LANG': 'C.UTF-8',
           'LC_ALL': 'C.UTF-8', 'PHPRC': '', 'PHP_INI_SCAN_DIR': '',
           'PAYMENTER_TEST_PHP': str(binary), 'PAYMENTER_TEST_CURL_EXTENSION': str(extension)}
    php = str(ROOT / 'scripts/php-test')
    suites = sorted((ROOT / 'tests').rglob('*-test.php'))
    if not suites:
        raise RuntimeError('No PHP suites found.')
    commands = [('lint', [php, '-l', str(p.relative_to(ROOT))])
                for area in ['NameSilo', 'tests'] for p in sorted((ROOT / area).rglob('*.php'))]
    commands += [('suite', [php, str(p.relative_to(ROOT))]) for p in suites]
    before, results = hashes(), []
    try:
        for index, (kind, argv) in enumerate(commands):
            try:
                result = subprocess.run(argv, cwd=ROOT, env=env, capture_output=True, timeout=60)
                stdout, stderr, code = result.stdout, result.stderr, result.returncode
            except subprocess.TimeoutExpired as error:
                stdout, stderr, code = error.stdout or b'', error.stderr or b'', 124
            (output / f'{index:03d}.stdout.txt').write_bytes(stdout)
            (output / f'{index:03d}.stderr.txt').write_bytes(stderr)
            unchanged = hashes() == before
            results.append({'kind': kind, 'file': argv[-1], 'exit': code, 'runtime_unchanged': unchanged})
            if kind != 'lint' or code:
                print(stdout.decode(errors='replace').strip(), flush=True)
            if stderr:
                print(stderr.decode(errors='replace').strip(), flush=True)
            if code or not unchanged:
                break
        complete = len(results) == len(commands) and all(r['exit'] == 0 and r['runtime_unchanged'] for r in results)
        receipt = {'complete': complete, 'php_version': '8.4.26', 'results': results,
                   'runtime_sha256': before, 'scope': 'Synthetic offline PHP only; no Paymenter integration.'}
        (output / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')
        print(json.dumps({'complete': complete, 'commands_run': len(results),
                          'commands_planned': len(commands), 'receipt': str(output / 'receipt.json')}))
        return 0 if complete else 1
    finally:
        shutil.rmtree(runtime)


if __name__ == '__main__':
    raise SystemExit(main())
