"""Syntax-check actual inline scripts after Laravel renders every shared-script consumer."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from html.parser import HTMLParser
import os
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
        expected = consumers | {'partials.client-script', 'label-company', 'label-app', 'label-neutral', 'label-quoted'}
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
        print(f'Syntax checked {count} inline scripts in {len(paths)} real Blade renders; company labels preserved.')


if __name__ == '__main__':
    main()
