# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Registrar API clients stay protocol code: each file under Registrars/<Provider>/Api names only that subtree.

A `use` import or a fully qualified class name outside that namespace must appear in ALLOWED. The list records
what the clients reach today and may only shrink: remove an entry when its last reference goes, and move code
instead of adding one. Adding an entry to make this test pass is a review block. See docs/design/layout.md.
"""
from pathlib import Path
import re
import tempfile
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

USE = re.compile(r'^\s*use\s+(?:function\s+|const\s+)?([^;]+);', re.MULTILINE)
QUALIFIED = re.compile(r'(?<![\w\\])\\([A-Z]\w*(?:\\[A-Z]\w*)+)')
NAMESPACE = re.compile(r'^\s*namespace\s+([\w\\]+)\s*[;{]', re.MULTILINE)
DECLARATION = re.compile(r'^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|trait|interface|enum)\s', re.MULTILINE)


def names(source):
    """Every class a PHP file imports, uses as a trait or names fully qualified, without the leading backslash.

    `use` before the first class-like declaration imports an absolute name. Inside a class body `use` names a trait,
    resolved against the file's namespace unless it starts with a backslash."""
    found = set(QUALIFIED.findall(source))
    body = DECLARATION.search(source)
    header, classes = (source[:body.start()], source[body.start():]) if body else (source, '')
    namespace = (NAMESPACE.search(source) or [None, ''])[1]
    for statement in USE.findall(classes):
        found.update(item if item.startswith('\\') else namespace+'\\'+item
                     for item in (part.strip() for part in statement.split(',')) if item)
    found = {name.lstrip('\\') for name in found}
    for statement in USE.findall(header):
        group = re.fullmatch(r'\s*\\?([\w\\]+)\\\{([^}]*)\}\s*', statement)
        items = [item.strip() for item in (group[2] if group else statement).split(',')]
        found.update((group[1]+'\\' if group else '')+re.split(r'\s+as\s+', item)[0].lstrip('\\')
                     for item in items if item)
    return found


def outside(path, root=API):
    """Names in one API file outside its own namespace, relative to the Domains root namespace."""
    source = path.read_text()
    # The provider's whole Api subtree is one namespace, so nested protocol files may use each other.
    own = BASE+'Registrars\\'+path.relative_to(root).parts[0]+'\\Api\\'
    # A name with one segment is global PHP (\InvalidArgumentException), never a Domains type.
    return sorted(n[len(BASE):] if n.startswith(BASE) else n
                  for n in names(source) if '\\' in n and not n.startswith(own))


def current(root=API):
    return {str(p.relative_to(root)): found for p in sorted(root.glob('*/Api/**/*.php')) if (found := outside(p, root))}


class RegistrarApiImports(unittest.TestCase):
    def test_api_files_name_only_their_namespace_and_the_allowlist(self):
        added = [f'{file}: {name}' for file, found in current().items()
                 for name in found if name not in ALLOWED.get(file, [])]
        self.assertEqual([], added, 'Registrar API code reaches outside its namespace; keep protocol code '
                         'self-contained or move the logic out of Api/ (never extend ALLOWED):\n' + '\n'.join(added))

    def test_allowlist_only_shrinks(self):
        found = current()
        stale = [f'{file}: {name}' for file, allowed in ALLOWED.items()
                 for name in allowed if name not in found.get(file, [])]
        self.assertEqual([], stale, 'Remove these ALLOWED entries; the reference is gone:\n' + '\n'.join(stale))

    def test_reader_sees_grouped_aliased_and_qualified_names(self):
        source = ('<?php\nnamespace A\\B;\nuse C\\{D, E as F};\nuse G\\H as I;\n'
                  'class X { use Trait; function f() { return "\\r\\n".\\J\\K::L; } }\n')
        self.assertEqual({'C\\D', 'C\\E', 'G\\H', 'J\\K'}, names(source))
        bracketed = '<?php\nnamespace A\\B {\n    use \\C\\{D};\n    use E\\F;\n}\n'
        self.assertEqual({'C\\D', 'E\\F'}, names(bracketed))

    def test_nested_api_files_are_scanned_and_relative_traits_stay_inside(self):
        api = BASE+'Registrars\\Enom\\Api'
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'Enom/Api/Protocol').mkdir(parents=True)
            (root/'Enom/Api/Client.php').write_text(
                f'<?php\nnamespace {api};\nuse {BASE}Data\\Environment;\n'
                'final class Client\n{\n    use Protocol\\EncodesParameters;\n}\n')
            (root/'Enom/Api/Protocol/Request.php').write_text(
                f'<?php\nnamespace {api}\\Protocol;\nuse {api}\\Client;\nuse {BASE}Services\\OperationClaims;\n'
                'trait EncodesParameters\n{\n    use \\Other\\Shared;\n}\n')
            self.assertEqual({'Enom/Api/Client.php': ['Data\\Environment'],
                              'Enom/Api/Protocol/Request.php': ['Other\\Shared', 'Services\\OperationClaims']},
                             current(root))

if __name__ == '__main__':
    unittest.main()
