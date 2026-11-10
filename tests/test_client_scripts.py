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
        if tag == 'td' and 'data-saved-ttl' in attrs and self.row is not None:
            self.row['ttl'] = attrs
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
        expected = consumers | {'partials.client-script', 'label-company', 'label-app', 'label-neutral', 'label-quoted', 'show-records', 'show-management-pending', 'show-order-pending'}
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
        pending = InlineScripts()
        pending.feed((Path(temporary)/'show-management-pending.html').read_text())
        checked = subprocess.run([node, str(ROOT/'tests/Support/management-poll.js')],
                                 input=pending.scripts[-1], capture_output=True, text=True, timeout=10)
        assert checked.returncode == 0, checked.stdout + checked.stderr
        pending = InlineScripts()
        pending.feed((Path(temporary)/'show-order-pending.html').read_text())
        order = [script for script in pending.scripts if '/order-status' in script]
        assert len(order) == 1, 'A pending order renders one status script'
        checked = subprocess.run([node, str(ROOT/'tests/Support/order-poll.js')],
                                 input=order[0], capture_output=True, text=True, timeout=10)
        assert checked.returncode == 0, checked.stdout + checked.stderr
        print(checked.stdout.strip())
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
        checked = subprocess.run([node, str(ROOT/'tests/Support/record-ttl.js')],
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
        # Dispatch term changes through the actual rendered registration handler.
        checked = subprocess.run([node, '-e', r'''
const assert = require('node:assert/strict');
const vm = require('node:vm');
const script = require('node:fs').readFileSync(0, 'utf8');
const listeners = {};
const prices = [{textContent: '$12.00'}, {textContent: '$19.00'}];
const list = {dataset: {}, addEventListener: (name, handler) => { listeners[name] = handler; },
    querySelectorAll: () => []};
const document = {getElementById: () => null, addEventListener() {},
    querySelectorAll: selector => selector === '[data-domains-results]' ? [list] : []};
vm.runInNewContext(script, {document});
assert.equal(typeof listeners.change, 'function', 'Registration list has a change handler');
for (const total of ['$30.00 for 3 years', '$12.00 for 1 year']) {
    const select = {matches: selector => selector === 'select[name="term"]',
        selectedOptions: [{dataset: {total}}],
        closest: selector => { assert.equal(selector, '[data-domains-result]');
            return {querySelector: selector => { assert.equal(selector, '.domains-result__price strong'); return prices[0]; }}; }};
    listeners.change({target: select});
    assert.equal(prices[0].textContent, total, 'Selected term updates its result price');
    assert.equal(prices[1].textContent, '$19.00', 'Other result price stays intact');
}
listeners.change({target: {matches: () => false}});
'''], input=scripts.scripts[0], capture_output=True, text=True, timeout=10)
        assert checked.returncode == 0, checked.stdout + checked.stderr
        # Execute the actual rendered summary closure with transfer result rows.
        checked = subprocess.run([node, '-e', r'''
const assert = require('node:assert/strict');
const vm = require('node:vm');
const script = require('node:fs').readFileSync(0, 'utf8');
const start = script.indexOf('const summary = () => {');
const end = script.indexOf('const check = async row =>', start);
assert.ok(start >= 0 && end > start);
for (const [state, expected] of [['busy', 'Try again in a moment.'],
    ['transferable', 'This name can be transferred. Choose Transfer to continue.'],
    ['in_cart', 'Already in your cart. Go to your cart to continue.'],
    ['error', 'Couldn’t check this transfer. Retry to check it.']]) {
    const live = {textContent: ''};
    const row = {dataset: {state}, querySelector: () => ({textContent: ''})};
    const list = {dataset: {mode: 'transfer'}, querySelectorAll: () => [row],
        querySelector: selector => selector === '[data-busy]' ? null : row};
    vm.runInNewContext(script.slice(start, end) + '; summary();', {live, list});
    assert.equal(live.textContent, expected, state + ' transfer announcement');
}
'''], input=scripts.scripts[0], capture_output=True, text=True, timeout=10)
        assert checked.returncode == 0, checked.stdout + checked.stderr
        scripts = InlineScripts()
        scripts.feed((Path(temporary)/'partials.client-script.html').read_text())
        checked = subprocess.run([node, str(ROOT/'tests/Support/contact-suggestions.js')],
                                 input=json.dumps({'contacts': json.loads((Path(temporary)/'contact-suggestions.json').read_text()),
                                                   'script': scripts.scripts[0]}),
                                 capture_output=True, text=True, timeout=10)
        assert checked.returncode == 0, checked.stdout + checked.stderr
        print(checked.stdout.strip())
        print(f'Syntax checked {count} inline scripts in {len(paths)} real Blade renders; company labels preserved.')


if __name__ == '__main__':
    main()
