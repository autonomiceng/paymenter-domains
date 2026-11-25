"""Check the heading and invoice-only actions in the actual offline domain-page render artifacts."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from html.parser import HTMLParser
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


class Actions(HTMLParser):
    def __init__(self):
        super().__init__()
        self.actions = []
        self.current = None
        self.headings = []
        self.heading = None

    def handle_starttag(self, tag, attrs):
        # Every h1 start counts, closed or not, as the browser builds one heading for each.
        if tag == 'h1':
            self.heading = []
            self.headings.append(self.heading)
        if tag in ('a', 'button'):
            self.current = {'tag': tag, 'href': dict(attrs).get('href'), 'text': []}

    def handle_data(self, data):
        if self.heading is not None:
            self.heading.append(data)
        if self.current is not None:
            self.current['text'].append(data)

    def handle_endtag(self, tag):
        if tag == 'h1':
            self.heading = None
        if self.current is not None and tag == self.current['tag']:
            self.actions.append((' '.join(''.join(self.current['text']).split()), self.current['href']))
            self.current = None


def main():
    fixture = Path(os.environ['PAYMENTER_TEST_FIXTURE'])
    with tempfile.TemporaryDirectory(prefix='domains-page-artifacts-', dir=fixture.parent) as temporary:
        result = subprocess.run([str(ROOT/'scripts/php-test'), 'tests/domain-page-render.php', temporary],
                                cwd=ROOT, capture_output=True, text=True, timeout=60)
        assert result.returncode == 0, result.stdout + result.stderr
        for mode in ('dark', 'light'):
            artifact = Path(temporary)/f'domain-page-invoice-due-{mode}.html'
            assert artifact.is_file(), f'{mode}: invoice-due artifact must be generated'
            parser = Actions()
            parser.feed(artifact.read_text())
            assert ('Pay invoice', '/invoices/1043') in parser.actions, f'{mode}: current invoice is payable'
            assert all(label != 'Renew now' for label, _ in parser.actions), f'{mode}: no competing renewal request'
            headings = [' '.join(''.join(heading).split()) for heading in parser.headings]
            assert len(headings) == 1 and 'invoice-due.example' in headings[0], f'{mode}: one heading names the domain: {headings}'
        print('Invoice-due dark/light artifacts name the domain and show Pay invoice without Renew now.')


if __name__ == '__main__':
    main()
