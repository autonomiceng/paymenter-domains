#!/usr/bin/env python3
"""Render native reminder/staff fixtures offline, or run selected native checks. No installed app."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from bootstrap import prepare
import native

ROOT = Path(__file__).resolve().parents[1]


def main():
    if len(sys.argv) < 2:
        sys.exit('usage: render-reminders.py OUTPUT_DIR [native test paths]')
    destination = Path(sys.argv[1]).resolve()
    destination.mkdir(parents=True, exist_ok=True)
    source = os.environ['PAYMENTER_TEST_APP']
    fixture = Path(tempfile.mkdtemp(prefix='domains-reminder-ui-'))/'paymenter'
    runtime = None
    try:
        before = native.prepare(source, fixture)
        runtime, binary, extension = prepare(native=True)
        env = dict(os.environ, PAYMENTER_TEST_PHP=str(binary), PAYMENTER_TEST_CURL_EXTENSION=str(extension),
                   PAYMENTER_TEST_NATIVE_EXTENSIONS=str(extension.parent), PAYMENTER_TEST_FIXTURE=str(fixture),
                   LD_LIBRARY_PATH=str(runtime/'usr/lib/x86_64-linux-gnu'))
        paths = sys.argv[2:] or ['tests/reminders-render.php']
        for path in paths:
            subprocess.run([str(ROOT/'scripts/php-test'), path, str(destination)], env=env, cwd=ROOT, check=True)
        if native.verify(source)[1] != before:
            raise RuntimeError('Read-only native dependency changed')
        print(destination)
    finally:
        if runtime:
            shutil.rmtree(runtime)
        shutil.rmtree(fixture.parent)


if __name__ == '__main__':
    main()
