#!/usr/bin/env python3
"""Render actual storefront Blade with pinned Paymenter, synthetic DTOs and a supplied site shell. Offline only.

Usage: render-storefront.py SHELL_DIR OUTPUT_DIR. SHELL_DIR holds the rendered site page ref-domains-register.html
and its built assets; OUTPUT_DIR receives the static pages, which are also copied into SHELL_DIR as ui32-*.html.
"""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import os
from html import escape
from html.parser import HTMLParser
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
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
    if len(sys.argv) != 3:
        sys.exit('usage: render-storefront.py SHELL_DIR OUTPUT_DIR')
    ASSETS, DEST = Path(sys.argv[1]).resolve(), Path(sys.argv[2]).resolve()
    source = os.environ['PAYMENTER_TEST_APP']
    fixture = Path(tempfile.mkdtemp(prefix='domains-ui32-'))/'paymenter'
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
                   LD_LIBRARY_PATH=str(runtime/'usr/lib/x86_64-linux-gnu'), UI32_OUTPUT=str(DEST))
        DEST.mkdir(parents=True, exist_ok=True)
        (fixture/'ui32-shell.html').write_text(shell)
        subprocess.run([str(ROOT/'scripts/php-test'), str(ROOT/'tests/Support/storefront-render.php')], env=env, cwd=ROOT, check=True)
        for page in DEST.glob('*.html'):
            shutil.copy2(page, ASSETS/('ui32-'+page.name))
        if native.verify(source)[1] != before:
            raise RuntimeError('Read-only dependency changed')
        print(DEST)
    finally:
        if runtime:
            shutil.rmtree(runtime)
        shutil.rmtree(fixture.parent)

if __name__ == '__main__':
    main()
