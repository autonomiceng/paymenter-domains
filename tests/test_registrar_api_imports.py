# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Registrar API clients stay protocol code: each Registrars/<Provider>/Api file names only its own namespace.

A `use` import or a fully qualified class name outside that namespace must appear in ALLOWED. The list records
what the clients reach today and may only shrink: remove an entry when its last reference goes, and move code
instead of adding one. See docs/design/layout.md.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
API = ROOT/'Domains/Registrars'
BASE = 'Paymenter\\Extensions\\Servers\\Domains\\'

ALLOWED = {
    'Enom/Api/AdoptionTransport.php': ['Data\\Environment'],
    'Enom/Api/Client.php': ['Data\\AccountBinding', 'Data\\RequestContext', 'Services\\EnomTestCallLimit'],
    'Enom/Api/CurlTransport.php': ['Data\\Environment', 'Services\\EnomLiveCalls', 'Services\\EnomTestCallLimit',
                                   'Services\\EnomTestCalls', 'Services\\EnomTestSendLimit',
                                   'Services\\ProviderSendGuard'],
    'Enom/Api/ProtocolResult.php': ['Data\\RegistrarReply', 'Data\\RegistrationRefusal'],
    'Enom/Api/ResponseParser.php': ['Data\\RegistrarReply'],
    'Enom/Api/Transport.php': ['Data\\Environment'],
    'NameSilo/Api/CurlTransport.php': ['Services\\ProviderSendGuard'],
    'NameSilo/Api/DnsMapping.php': ['Data\\CanonicalName', 'Registrars\\NameSilo\\Dns'],
    'NameSilo/Api/DomainName.php': ['Data\\CanonicalName'],
    'NameSilo/Api/DsMapping.php': ['Data\\CanonicalName', 'Registrars\\NameSilo\\Ds',
                                   'Registrars\\NameSilo\\DsRegistryPolicy'],
    'NameSilo/Api/RegistrarClient.php': ['Services\\LiveReservationLimit'],
    'NameSilo/Api/RegistrarRequest.php': ['Data\\CanonicalName'],
    'NameSilo/Api/ResponseParser.php': ['Registrars\\NameSilo\\DefaultContact'],
}

USE = re.compile(r'^use\s+(?:function\s+|const\s+)?([^;]+);', re.MULTILINE)
QUALIFIED = re.compile(r'(?<![\w\\])\\([A-Z]\w*(?:\\[A-Z]\w*)+)')


def names(source):
    """Every class a PHP file imports or names fully qualified, without the leading backslash."""
    found = set(QUALIFIED.findall(source))
    for statement in USE.findall(source):
        group = re.fullmatch(r'\s*([\w\\]+)\\\{([^}]*)\}\s*', statement)
        items = [item.strip() for item in (group[2] if group else statement).split(',')]
        found.update((group[1]+'\\' if group else '')+re.split(r'\s+as\s+', item)[0].lstrip('\\')
                     for item in items if item)
    return found


def outside(path):
    """Names in one API file outside its own namespace, relative to the Domains root namespace."""
    source = path.read_text()
    own = re.search(r'^namespace\s+([\w\\]+);', source, re.MULTILINE)[1]+'\\'
    # A name with one segment is global PHP (\InvalidArgumentException), never a Domains type.
    return sorted(n[len(BASE):] if n.startswith(BASE) else n
                  for n in names(source) if '\\' in n and not n.startswith(own))


def current():
    return {str(p.relative_to(API)): found for p in sorted(API.glob('*/Api/*.php')) if (found := outside(p))}


class RegistrarApiImports(unittest.TestCase):
    def test_api_files_name_only_their_namespace_and_the_allowlist(self):
        added = [f'{file}: {name}' for file, found in current().items()
                 for name in found if name not in ALLOWED.get(file, [])]
        self.assertEqual([], added, 'Registrar API code reaches outside its namespace; keep protocol code '
                         'self-contained or move the logic out of Api/:\n' + '\n'.join(added))

    def test_allowlist_only_shrinks(self):
        found = current()
        stale = [f'{file}: {name}' for file, allowed in ALLOWED.items()
                 for name in allowed if name not in found.get(file, [])]
        self.assertEqual([], stale, 'Remove these ALLOWED entries; the reference is gone:\n' + '\n'.join(stale))

    def test_reader_sees_grouped_aliased_and_qualified_names(self):
        source = ('<?php\nnamespace A\\B;\nuse C\\{D, E as F};\nuse G\\H as I;\n'
                  'class X { use Trait; function f() { return "\\r\\n".\\J\\K::L; } }\n')
        self.assertEqual({'C\\D', 'C\\E', 'G\\H', 'J\\K'}, names(source))


if __name__ == '__main__':
    unittest.main()
