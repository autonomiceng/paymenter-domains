# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Registrar API clients stay protocol code, and the rest of Domains reaches a provider only through Dispatch.

Each file under Registrars/<Provider>/Api names only that subtree. A `use` import or a fully qualified class name
outside that namespace must appear in ALLOWED. The list records what the clients reach today and may only shrink:
remove an entry when its last reference goes, and move code instead of adding one.

Outside the provider trees and Registrars/Dispatch, every reference to a provider class is listed in
tests/registrar-reverse-imports.json: Registrars/Enom and Registrars/NameSilo classes, the Enom* and NameSilo*
classes still in Services, the Enom-only Services/ApiCallLimits, and provider class names or source paths written
in strings. The list must match the code exactly. Delete an entry when its reference goes, and call a Dispatch
entry point instead of adding one.
Adding an entry to make this test pass is a review block. See docs/design/layout.md.
"""
import json
from pathlib import Path
import re
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
API = ROOT/'Domains/Registrars'
BASE = 'Paymenter\\Extensions\\Servers\\Domains\\'
REVERSE = ROOT/'tests/registrar-reverse-imports.json'
PROVIDERS = ('Registrars/Enom/', 'Registrars/NameSilo/')
# Dispatch files that build provider objects or classify their refusals. Other Dispatch files stay provider neutral.
COMPOSITION = {'Registrars/Dispatch/Offers.php', 'Registrars/Dispatch/RegistrarFactories.php',
               'Registrars/Dispatch/RegistrarRegistry.php', 'Registrars/Dispatch/RenewalWriter.php'}
# One provider naming the other. Only shrinks: the shared pre-wire refusal type replaces this Enom one.
CROSSING = ['NameSilo/ObservationReader.php: Services\\EnomTestCallLimit']

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

# Any PHP identifier segment, whatever its initial case: \Vendor\protocol\Client is a qualified name.
QUALIFIED = re.compile(r'(?<![\w\\])\\([A-Za-z_]\w*(?:\\[A-Za-z_]\w*)+)')
# Comments, strings, heredocs and nowdocs, blanked before reading scope so their braces and words do not count.
NOISE = re.compile(r"""<<<[ \t]*(['"]?)(\w+)\1\r?\n.*?^[ \t]*\2\b|//[^\n]*|#(?!\[)[^\n]*|/\*.*?\*/"""
                   r"""|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*\"""", re.DOTALL | re.MULTILINE)
SCOPE = re.compile(r'(?<![\w$>\\])(?:namespace\s+(?P<space>[\w\\]*)\s*(?P<opener>[;{])'
                   r'|use\s+(?!\()(?:function\s+|const\s+)?(?P<text>[^;{]*)(?P<block>\{[^}]*\})?\s*;?)|(?P<brace>[{}])')


def names(source):
    """Every class a PHP file imports, uses as a trait or names fully qualified, without the leading backslash.

    PHP decides by scope: `use` at namespace level imports an absolute name wherever it appears in the file, and
    `use` inside a class-like body names a trait, resolved against the file's namespace unless it starts with a
    backslash."""
    code = NOISE.sub("''", source)
    found = set(QUALIFIED.findall(code))
    namespace, level, depth = '', 0, 0
    for match in SCOPE.finditer(code):
        if match['brace']:
            depth += 1 if match['brace'] == '{' else -1
        elif match['opener']:
            namespace = match['space']
            depth += match['opener'] == '{'
            level = depth
        elif depth == level:
            prefix = match['text'].strip().lstrip('\\') if match['block'] else ''
            items = (match['block'][1:-1] if match['block'] else match['text']).split(',')
            found.update(prefix+re.split(r'\s+as\s+', item.strip())[0].lstrip('\\') for item in items if item.strip())
        else:
            found.update(item if item.startswith('\\') else namespace+'\\'+item
                         for item in (part.strip() for part in match['text'].split(',')) if item)
    return {name.lstrip('\\') for name in found}


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


# Legacy Services classes and files that belong to Enom without carrying its name.
ENOM_SERVICES = ('Services\\ApiCallLimits', 'path:Services/ApiCallLimits.php')
PROVIDER = re.compile(r'Registrars\\(?:Enom|NameSilo)\\\w|Services\\(?:(?:Enom|NameSilo)\w*|ApiCallLimits)$')
# A name in code: not a variable, member or class constant, and not the tail of a longer name.
TOKEN = re.compile(r'(?<!::)(?<!->)(?<![\w$\\])\\?[A-Za-z_]\w*(?:\\[A-Za-z_]\w*)*')
STRING_NAME = re.compile(r'Registrars\\{1,2}(?:Enom|NameSilo)\\{1,2}\w+(?:\\{1,2}\w+)*'
                         r'|Services\\{1,2}(?:(?:Enom|NameSilo)\w*|ApiCallLimits\b)')
STRING_PATH = re.compile(r'(?:Registrars/)?(?:Enom|NameSilo)/[\w/]*\w\.php'
                         r'|Services/(?:(?:Enom|NameSilo)\w*|ApiCallLimits)\.php')
# PHP in Blade: `@php ... @endphp` blocks and `@php(...)` expressions, compiled into the global namespace.
BLADE_PHP = re.compile(r'@php\b(?!\s*\()(.*?)@endphp|@php\s*(\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))', re.DOTALL)


def provider(name):
    """A provider class relative to the Domains root namespace, or None."""
    relative = name[len(BASE):] if name.startswith(BASE) else ''
    return relative if PROVIDER.match(relative) else None


def provider_names(source, blade=False, own=None):
    """Provider classes one runtime file reaches, resolved the way PHP resolves them.

    Imports (grouped and aliased) count, and so does each use of an imported namespace prefix (`Enom\\Driver` after
    `use ...\\Registrars\\{Enom}`), a relative name, `namespace\\` and a fully qualified name. Aliases match in any
    case, as PHP matches them. Strings count when they hold a provider class name or source path. Blade counts fully
    qualified names anywhere and reads the PHP inside `@php` as a file in the global namespace."""
    found = set()
    for match in NOISE.finditer(source):
        text = match.group(0)
        if text[:1] in '\'"' or text.startswith('<<<'):
            found.update(re.sub(r'\\+', r'\\', name) for name in STRING_NAME.findall(text))
            found.update('path:'+path for path in STRING_PATH.findall(text))
    if blade:
        found.update(name for name in map(provider, QUALIFIED.findall(source)) if name)
        for block in BLADE_PHP.finditer(source):
            found |= provider_names('<?php\n'+(block[1] if block[1] is not None else block[2])+';\n')
        return found
    code = NOISE.sub("''", source)
    namespace, level, depth, aliases, spans = '', 0, 0, {}, []
    for match in SCOPE.finditer(code):
        if match['brace']:
            depth += 1 if match['brace'] == '{' else -1
            continue
        spans.append(match.span())
        if match['opener']:
            namespace, aliases = match['space'], {}
            depth += match['opener'] == '{'
            level = depth
        elif depth == level:
            if re.match(r'(?:function|const)\s', match['text']):
                continue
            prefix = match['text'].strip().lstrip('\\') if match['block'] else ''
            for item in (match['block'][1:-1] if match['block'] else match['text']).split(','):
                if not item.strip():
                    continue
                name, *alias = re.split(r'\s+as\s+', item.strip())
                full = prefix+name.lstrip('\\')
                aliases[(alias[0] if alias else full.rsplit('\\', 1)[-1]).lower()] = full
                found.add(provider(full))
        else:
            found.update(provider(item.lstrip('\\') if item.startswith('\\') else namespace+'\\'+item)
                         for item in (part.strip() for part in match['text'].split(',')) if item)
    # Blank namespace and use statements so their names are not read again as uses.
    body = list(code)
    for start, end in spans:
        body[start:end] = ' '*(end-start)
    for name in TOKEN.findall(''.join(body)):
        head, _, rest = name.partition('\\')
        if name.startswith('\\'):
            full = name[1:]
        elif head.lower() == 'namespace' and rest:
            full = namespace+'\\'+rest if namespace else rest
        elif head.lower() in aliases:
            full = aliases[head.lower()]+('\\'+rest if rest else '')
        else:
            full = namespace+'\\'+name if namespace else name
        found.add(provider(full))
    found.discard(None)
    if own:
        found.discard(provider(namespace+'\\'+own))
    return found


def reverse(root=ROOT/'Domains'):
    """Provider names reached by each runtime file outside the provider trees, Dispatch included."""
    found = {}
    for path in sorted(root.rglob('*.php')):
        relative = str(path.relative_to(root))
        if relative.startswith(PROVIDERS):
            continue
        blade = relative.endswith('.blade.php')
        if names := provider_names(path.read_text(), blade, None if blade else path.stem):
            found[relative] = sorted(names)
    return found


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
        self.assertEqual({'A\\B\\Trait', 'C\\D', 'C\\E', 'G\\H', 'J\\K'}, names(source))
        bracketed = '<?php\nnamespace A\\B {\n    use \\C\\{D};\n    use E\\F;\n}\n'
        self.assertEqual({'C\\D', 'E\\F'}, names(bracketed))
        late = ('<?php\nnamespace A;\nconst PREFIX = <<<\'JSON\'\n{\n    JSON;\n'
                'enum Encoding\n{\n    case Url; // use Not\\Code;\n}\n'
                'use B\\C;\nfinal class Y\n{\n    use T;\n'
                '    function f() { return function () use ($x) { return "{$x}"; }; }\n}\n')
        self.assertEqual({'A\\T', 'B\\C'}, names(late))

    def test_reader_sees_lowercase_segments_and_skips_comments_and_strings(self):
        code = '<?php\nnamespace A;\nfinal class Y { function f() { return new \\Vendor\\protocol\\Client(); } }\n'
        self.assertEqual({'Vendor\\protocol\\Client'}, names(code))
        comment = f'<?php\nnamespace A;\n// Callers reach \\{BASE}Services\\OperationClaims here.\nfinal class Y {{}}\n'
        self.assertEqual(set(), names(comment))
        string = f'<?php\nnamespace A;\nfinal class Y {{ const NAME = "\\{BASE}Services\\OperationClaims"; }}\n'
        self.assertEqual(set(), names(string))

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

    def test_reverse_imports_match_the_recorded_list(self):
        found = {file: names for file, names in reverse().items() if not file.startswith('Registrars/Dispatch/')}
        recorded = json.loads(REVERSE.read_text())
        added = [f'{file}: {name}' for file, names in found.items() for name in names if name not in recorded.get(file, [])]
        stale = [f'{file}: {name}' for file, names in recorded.items() for name in names if name not in found.get(file, [])]
        self.assertEqual([], added, 'Runtime code reaches a provider class directly; call a Registrars/Dispatch entry '
                         'point instead (never extend tests/registrar-reverse-imports.json):\n' + '\n'.join(added))
        self.assertEqual([], stale, 'Remove these entries from tests/registrar-reverse-imports.json; the reference '
                         'is gone:\n' + '\n'.join(stale))
        self.assertEqual(sorted(recorded), list(recorded), 'Keep tests/registrar-reverse-imports.json sorted by file')

    def test_only_composition_files_in_dispatch_reach_a_provider(self):
        reaching = sorted(file for file in reverse() if file.startswith('Registrars/Dispatch/'))
        self.assertEqual([], [file for file in reaching if file not in COMPOSITION])

    def test_providers_do_not_name_each_other(self):
        crossing = []
        for own, other in (('Enom', 'NameSilo'), ('NameSilo', 'Enom')):
            for path in sorted((API/own).rglob('*.php')):
                crossing += [f'{path.relative_to(API)}: {name}' for name in sorted(provider_names(path.read_text()))
                             if name.startswith((f'Registrars\\{other}\\', f'Services\\{other}', f'path:{other}/',
                                                 f'path:Registrars/{other}/', f'path:Services/{other}'))
                             or other == 'Enom' and name in ENOM_SERVICES]
        self.assertEqual(CROSSING, crossing, 'A provider names the other provider; move the shared part out instead')

    def test_reverse_reader_resolves_prefixes_aliases_strings_and_blade(self):
        source = (f'<?php\nnamespace {BASE}Catalogue;\nuse {BASE}Registrars\\{{Enom, NameSilo as Silo}};\n'
                  f'use {BASE}Registrars as R;\nuse {BASE}Services\\{{EnomLive, Storefront}};\n'
                  'final class Probe { function f(): Enom\\Driver { R\\NameSilo\\Dns::x(); Silo\\Glue::y();\n'
                  '    $this->Enom; self::EnomLive; return "\\\\' + BASE.replace('\\', '\\\\') + 'Registrars\\\\Enom\\\\Writer"'
                  ' . \'/NameSilo/AdmittedTransport.php\'; } }\n')
        self.assertEqual({'Registrars\\Enom\\Driver', 'Registrars\\NameSilo\\Dns', 'Registrars\\NameSilo\\Glue',
                          'Registrars\\Enom\\Writer', 'Services\\EnomLive', 'path:NameSilo/AdmittedTransport.php'},
                         provider_names(source))
        relative = f'<?php\nnamespace {BASE[:-1]};\nfinal class EnomLike {{ function f() {{ Registrars\\Enom\\Driver::x(); }} }}\n'
        self.assertEqual({'Registrars\\Enom\\Driver'}, provider_names(relative))
        own = f'<?php\nnamespace {BASE}Services;\nfinal class EnomLive {{ function f() {{ EnomTestCalls::x(); }} }}\n'
        self.assertEqual({'Services\\EnomTestCalls'}, provider_names(own, own='EnomLive'))
        blade = f'@foreach (\\{BASE}Registrars\\NameSilo\\DnsForm::notes($x) as $note) Don\'t @endforeach'
        self.assertEqual({'Registrars\\NameSilo\\DnsForm'}, provider_names(blade, blade=True))

    def test_reverse_reader_matches_aliases_in_any_case(self):
        source = (f'<?php\nnamespace {BASE}Catalogue;\nuse {BASE}Registrars as R;\n'
                  'final class P { const C = r\\NameSilo\\Client::class; }\n')
        self.assertEqual({'Registrars\\NameSilo\\Client'}, provider_names(source))

    def test_reverse_reader_reads_php_inside_blade(self):
        blade = (f'@php\n    use {BASE}Registrars\\NameSilo\\Client;\n@endphp\n'
                 f'@php($driver = {BASE}Registrars\\Enom\\Driver::make())\n{{{{ Client::name() }}}}\n')
        self.assertEqual({'Registrars\\NameSilo\\Client', 'Registrars\\Enom\\Driver'},
                         provider_names(blade, blade=True))

    def test_reverse_reader_counts_the_enom_call_limit_service(self):
        source = (f'<?php\nnamespace {BASE}Catalogue;\nuse {BASE}Services\\ApiCallLimits;\n'
                  'final class P { function f() { ApiCallLimits::save(); } }\n')
        self.assertEqual({'Services\\ApiCallLimits'}, provider_names(source))


if __name__ == '__main__':
    unittest.main()
