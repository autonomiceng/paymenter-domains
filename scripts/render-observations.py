#!/usr/bin/env python3
"""Render actual initial-record observations in the supplied site shell. Offline native fixture only."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import os
from pathlib import Path
import re
import runpy
import shutil
import subprocess
import sys
import tempfile
from bootstrap import prepare
import native

ROOT = Path(__file__).resolve().parents[1]
StaticShell = runpy.run_path(str(ROOT/'scripts/render-owned.py'))['StaticShell']


def main():
    if len(sys.argv) != 3:
        sys.exit('usage: render-observations.py SHELL_DIR OUTPUT_DIR')
    assets, dest = (Path(arg).resolve() for arg in sys.argv[1:])
    source = os.environ['PAYMENTER_TEST_APP']
    fixture = Path(tempfile.mkdtemp(prefix='domains-records-ui-'))/'paymenter'
    runtime = None
    try:
        before = native.prepare(source, fixture)
        runtime, binary, ext = prepare(native=True)
        shell = (assets/'ref-domains-register.html').read_text()
        shell = re.sub(r'<script\b[^>]*>.*?</script>', '', shell, flags=re.S | re.I)
        parser = StaticShell(); parser.feed(shell); shell = ''.join(parser.parts)
        shell = re.sub(r'<link[^>]+rel="modulepreload"[^>]*>', '', shell)
        shell = shell.replace('http://localhost:8080', '')
        shell = re.sub(r'(<main\b[^>]*>).*?(</main>)', r'\1@@SLOT@@\2', shell, flags=re.S)
        shell = re.sub(r'<html\b[^>]*>', '<html lang="en" class="dark">', shell, count=1)
        env = dict(os.environ, PAYMENTER_TEST_PHP=str(binary), PAYMENTER_TEST_CURL_EXTENSION=str(ext),
                   PAYMENTER_TEST_NATIVE_EXTENSIONS=str(ext.parent), PAYMENTER_TEST_FIXTURE=str(fixture),
                   LD_LIBRARY_PATH=str(runtime/'usr/lib/x86_64-linux-gnu'))
        dest.mkdir(parents=True, exist_ok=True)
        (fixture/'records-shell.html').write_text(shell)
        subprocess.run([str(ROOT/'scripts/php-test'), str(ROOT/'tests/observation-records-render.php'),
                        str(dest), str(fixture/'records-shell.html')], env=env, cwd=ROOT, check=True)
        if native.verify(source)[1] != before:
            raise RuntimeError('Read-only dependency changed')
        print(dest)
    finally:
        if runtime:
            shutil.rmtree(runtime)
        shutil.rmtree(fixture.parent)


if __name__ == '__main__':
    main()
