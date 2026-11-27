# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Every Domains namespace that draws random_bytes() has a seeded stream in tests/Support/fixture-random.php.

An unqualified random_bytes() call resolves to a namespace function first, so the fixture seeds one per namespace. A
namespace without one draws real randomness, and the case digests built from its keys differ on every run. Add a
shim beside the others when a test here names a namespace. A fully qualified \\random_bytes() call reaches the global
function, which no shim can seed, so Domains code calls it unqualified.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SHIMS = ROOT/'tests/Support/fixture-random.php'
CALL = re.compile(r'(?<![\\\w>:$])random_bytes\s*\(')
QUALIFIED = re.compile(r'\\random_bytes\s*\(')
NAMESPACE = re.compile(r'^namespace\s+([^;\s{]+)\s*[;{]', re.M)
SHIM = re.compile(r"namespace\s+([^\s{]+)\s*\{\s*if\s*\(!function_exists\(__NAMESPACE__\.'\\random_bytes'\)\)")


def drawing_namespaces() -> dict[str, list[str]]:
    found: dict[str, list[str]] = {}
    for path in sorted((ROOT/'Domains').rglob('*.php')):
        text = path.read_text(encoding='utf-8')
        if not CALL.search(text):
            continue
        match = NAMESPACE.search(text)
        found.setdefault(match.group(1) if match else '(global)', []).append(str(path.relative_to(ROOT)))
    return found


class FixtureRandomNamespacesTest(unittest.TestCase):
    def test_every_drawing_namespace_is_seeded(self) -> None:
        seeded = set(SHIM.findall(SHIMS.read_text(encoding='utf-8')))
        self.assertIn('Paymenter\\Extensions\\Servers\\Domains\\Services', seeded, 'shim pattern no longer matches')
        missing = {name: files for name, files in drawing_namespaces().items() if name not in seeded}
        self.assertEqual({}, missing, 'add a seeded random_bytes shim for each namespace in tests/Support/fixture-random.php')

    def test_no_fully_qualified_draws(self) -> None:
        qualified = [str(path.relative_to(ROOT)) for path in sorted((ROOT/'Domains').rglob('*.php'))
                     if QUALIFIED.search(path.read_text(encoding='utf-8'))]
        self.assertEqual([], qualified, 'call random_bytes() unqualified so the fixture shim can seed it')

    def test_namespace_pattern_reads_both_forms(self) -> None:
        self.assertEqual('A\\B', NAMESPACE.search('<?php\nnamespace A\\B;\n').group(1))
        self.assertEqual('A\\B', NAMESPACE.search('<?php\nnamespace A\\B {\n').group(1))

    def test_call_pattern_skips_qualified_and_method_calls(self) -> None:
        self.assertTrue(CALL.search('$k = bin2hex(random_bytes(16));'))
        self.assertTrue(QUALIFIED.search('$k = \\random_bytes(16);'))
        for text in ('\\random_bytes(4)', '$x->random_bytes(4)', 'Foo::random_bytes(4)', 'function random_bytes_x('):
            self.assertIsNone(CALL.search(text), text)


if __name__ == '__main__':
    unittest.main()
