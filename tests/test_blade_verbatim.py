"""Keep server-side Blade expressions out of browser-literal blocks."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
BLOCK = re.compile(r'@verbatim\b(.*?)@endverbatim\b', re.DOTALL)
EXPRESSION = re.compile(r'(?<![\w@])@[A-Za-z_]\w*|\{\{|\{!!')


def expressions(source):
    return [(source.count('\n', 0, block.start(1) + match.start()) + 1, match.group())
            for block in BLOCK.finditer(source)
            for match in EXPRESSION.finditer(block.group(1))]


class BladeVerbatimTests(unittest.TestCase):
    def test_all_domains_views_have_literal_verbatim_blocks(self):
        views = sorted((ROOT/'Domains/resources/views').rglob('*.blade.php'))
        self.assertTrue(views)
        hits = [f'{path.relative_to(ROOT)}:{line}: {token}' for path in views
                for line, token in expressions(path.read_text())]
        self.assertEqual([], hits, '\n'.join(hits))

    def test_guard_detects_directives_and_both_echo_forms(self):
        for token in ['@js($label)', '@if($ready)', '@custom($label)', '{{ $label }}', '{!! $label !!}']:
            with self.subTest(token=token):
                self.assertTrue(expressions('@verbatim\n' + token + '\n@endverbatim'))
        self.assertEqual([], expressions('@js($label)\n@verbatim\nconst value = {ready: true};\n@endverbatim'))


if __name__ == '__main__':
    unittest.main()
