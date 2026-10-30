"""Syntax-check actual inline scripts after Laravel renders every shared-script consumer."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from html.parser import HTMLParser
import os
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


class InlineScripts(HTMLParser):
    def __init__(self):
        super().__init__()
        self.scripts = []
        self.live = None
        self.current = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if attrs.get('id') == 'domains-live':
            self.live = attrs
        if tag == 'script' and 'src' not in attrs:
            self.current = []

    def handle_data(self, data):
        if self.current is not None:
            self.current.append(data)

    def handle_endtag(self, tag):
        if tag == 'script' and self.current is not None:
            self.scripts.append(''.join(self.current))
            self.current = None


class RecordForms(HTMLParser):
    """Extract the rendered form's submit order and actual existing record rows."""
    def __init__(self):
        super().__init__()
        self.forms = []
        self.form = None
        self.row = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form' and 'domains-record-form' in attrs.get('class', '').split():
            self.form = {'attrs': attrs, 'buttons': [], 'rows': []}
            self.forms.append(self.form)
        if self.form is None:
            return
        if tag == 'tr':
            self.row = {'attrs': attrs, 'fields': []}
            if 'data-record' in attrs:
                self.form['rows'].append(self.row)
        if tag in ('input', 'select') and self.row is not None:
            self.row['fields'].append(attrs)
        if tag == 'button' and attrs.get('type') == 'submit':
            self.form['buttons'].append(attrs)

    def handle_endtag(self, tag):
        if tag == 'tr':
            self.row = None
        if tag == 'form':
            self.form = None


def main():
    node = shutil.which('node')
    assert node, 'Node.js is required to syntax-check rendered client scripts'
    fixture = Path(os.environ['PAYMENTER_TEST_FIXTURE'])
    with tempfile.TemporaryDirectory(prefix='domains-client-scripts-', dir=fixture.parent) as temporary:
        result = subprocess.run([str(ROOT/'scripts/php-test'), 'tests/client-script-render.php', temporary],
                                cwd=ROOT, capture_output=True, text=True, timeout=60)
        assert result.returncode == 0, result.stdout + result.stderr
        paths = sorted(Path(temporary).glob('*.html'))
        # Discover consumers so adding a new include requires adding a render case too.
        consumers = {str(path.relative_to(ROOT/'Domains/resources/views'))[:-10]
                     for path in (ROOT/'Domains/resources/views').rglob('*.blade.php')
                     if re.search(r"@include\s*\(\s*['\"]domains::partials\.client-script['\"]", path.read_text())}
        expected = consumers | {'partials.client-script', 'label-company', 'label-app', 'label-neutral', 'label-quoted', 'show-records'}
        assert {path.stem for path in paths} == expected, 'Render cases must cover every client-script consumer'
        labels = {
            'label-company': 'Example Hosting nameservers filled in. Save to apply them.',
            'label-app': 'Example Cloud nameservers filled in. Save to apply them.',
            'label-neutral': 'Our nameservers filled in. Save to apply them.',
            'label-quoted': 'Example\'s "Cloud" <script> & Co nameservers filled in. Save to apply them.',
        }
        count = 0
        for path in paths:
            parser = InlineScripts()
            parser.feed(path.read_text())
            assert parser.live is not None and parser.scripts, f'{path.name}: shared script must render'
            for index, script in enumerate(parser.scripts):
                checked = subprocess.run([node, '--check', '-'], input=script, capture_output=True, text=True, timeout=10)
                assert checked.returncode == 0, f'{path.name} inline script {index}: {checked.stderr}'
                count += 1
            if path.stem in labels:
                assert parser.live.get('data-nameservers-filled') == labels[path.stem], f'{path.name}: unchanged label'
        records = RecordForms()
        records.feed((Path(temporary)/'show-records.html').read_text())
        assert len(records.forms) == 2, 'Both DNS and SRV forms must render'
        scripts = InlineScripts()
        scripts.feed((Path(temporary)/'show-records.html').read_text())
        checked = subprocess.run([node, str(ROOT/'tests/Support/record-submit.js')],
                                 input=json.dumps({'forms': records.forms, 'script': scripts.scripts[0]}),
                                 capture_output=True, text=True, timeout=10)
        assert checked.returncode == 0, checked.stdout + checked.stderr
        print(checked.stdout.strip())
        scripts = InlineScripts()
        scripts.feed((Path(temporary)/'search.html').read_text())
        checked = subprocess.run([node, str(ROOT/'tests/Support/search-summary.js')],
                                 input=json.dumps({'script': scripts.scripts[0]}), capture_output=True, text=True, timeout=10)
        assert checked.returncode == 0, checked.stdout + checked.stderr
        print(checked.stdout.strip())
        print(f'Syntax checked {count} inline scripts in {len(paths)} real Blade renders; company labels preserved.')


if __name__ == '__main__':
    main()
