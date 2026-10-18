#!/usr/bin/env python3
"""Render actual owned-domain Blade with pinned Paymenter, synthetic DTOs and the supplied site shell. Offline only."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import os
from html import escape
from html.parser import HTMLParser
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import sys
from bootstrap import prepare
import native

ROOT = Path(__file__).resolve().parents[1]

class StaticShell(HTMLParser):
    """Keep real shell markup/CSS while removing all executable theme directives."""
    def __init__(self):
        super().__init__(convert_charrefs=False)
        self.parts = []

    def handle_starttag(self, tag, attrs):
        values = []
        for key, value in attrs:
            if key == 'x-cloak':
                if tag != 'body':
                    values.append(' hidden')
            elif not key.startswith(('wire:', 'x-', ':', '@')):
                values.append(' '+key+(('="'+escape(value, quote=True)+'"') if value is not None else ''))
        self.parts.append('<'+tag+''.join(values)+'>')

    def handle_endtag(self, tag):
        self.parts.append('</'+tag+'>')

    def handle_data(self, data):
        self.parts.append(data)

    def handle_entityref(self, name):
        self.parts.append('&'+name+';')

    def handle_charref(self, name):
        self.parts.append('&#'+name+';')

    def handle_decl(self, decl):
        self.parts.append('<!'+decl+'>')


def main():
    if len(sys.argv) not in (3, 4):
        sys.exit('usage: render-owned.py SHELL_DIR OUTPUT_DIR [records]')
    ASSETS, DEST = Path(sys.argv[1]).resolve(), Path(sys.argv[2]).resolve()
    records = len(sys.argv) == 4 and sys.argv[3] == 'records'
    source = os.environ['PAYMENTER_TEST_APP']
    fixture = Path(tempfile.mkdtemp(prefix='domains-ui33-'))/'paymenter'
    runtime = None
    try:
        before = native.prepare(source, fixture)
        runtime, binary, ext = prepare(native=True)
        shell = (ASSETS/'ref-domains-register.html').read_text()
        shell = re.sub(r'<script\b[^>]*>.*?</script>', '', shell, flags=re.S | re.I)
        parser = StaticShell(); parser.feed(shell); shell = ''.join(parser.parts)
        shell = re.sub(r'<link[^>]+rel="modulepreload"[^>]*>', '', shell)
        shell = shell.replace('http://localhost:8080', '')
        shell = re.sub(r'(<main\b[^>]*>).*?(</main>)', r'\1@@SLOT@@\2', shell, flags=re.S)
        shell = re.sub(r'<html\b[^>]*>', '<html lang="en" class="dark">', shell, count=1)
        env = dict(os.environ, PAYMENTER_TEST_PHP=str(binary), PAYMENTER_TEST_CURL_EXTENSION=str(ext),
                   PAYMENTER_TEST_NATIVE_EXTENSIONS=str(ext.parent), PAYMENTER_TEST_FIXTURE=str(fixture),
                   LD_LIBRARY_PATH=str(runtime/'usr/lib/x86_64-linux-gnu'), OWNER_OUTPUT=str(DEST), OWNER_RECORDS='1' if records else '0')
        DEST.mkdir(parents=True, exist_ok=True)
        (fixture/'ui33-shell.html').write_text(shell)
        subprocess.run([str(ROOT/'scripts/php-test'), str(ROOT/'tests/Support/owned-render.php')], env=env, cwd=ROOT, check=True)
        for page in DEST.glob('*.html'):
            shutil.copy2(page, ASSETS/(('ui34-' if records else 'ui33-')+page.name))
        if native.verify(source)[1] != before:
            raise RuntimeError('Read-only dependency changed')
        print(DEST)
    finally:
        if runtime:
            shutil.rmtree(runtime)
        shutil.rmtree(fixture.parent)

if __name__ == '__main__':
    main()
