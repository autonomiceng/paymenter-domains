#!/usr/bin/env python3
"""Build and verify a deterministic runtime-only native extension archive."""
# Adapted from Paymenter Enom scripts/build.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import argparse
import hashlib
import json
from pathlib import Path
import re
import stat
import zipfile

ROOT = Path(__file__).resolve().parents[1]
ALLOWED_SUFFIXES = {'.php', '.json', '.css', '.js', '.svg'}
MAX_FILE_BYTES = 1_048_576
MAX_ARCHIVE_BYTES = 10_000_000
MAX_TOTAL_BYTES = 32 * MAX_FILE_BYTES
MANIFEST = 'Domains/manifest.json'
NO_RECEIPT = object()
PREFIX = 'Paymenter\\Extensions\\Servers\\Domains\\'
FORBIDDEN = {'scripts', 'tests', 'docs', 'private', 'node_modules', 'vendor', 'conversion', 'conversions', 'pins', 'dev'}
LEGAL = {'LICENSE', 'NOTICE', 'NOTICE.txt', 'ATTRIBUTION', 'ATTRIBUTION.txt'}

def php_list(tokens):
    """Split a PHP comma list without splitting nested expressions or strings."""
    groups, current, stack = [], [], []
    pairs = {b'(': b')', b'[': b']', b'#[': b']', b'{': b'}'}
    for token in tokens:
        if token == b',' and not stack:
            groups.append(current); current = []
            continue
        if token in pairs:
            stack.append(pairs[token])
        elif token in pairs.values():
            if not stack or stack.pop() != token:
                raise ValueError('Unbalanced ExtensionMeta declaration')
        current.append(token)
    if stack:
        raise ValueError('Unbalanced ExtensionMeta declaration')
    return groups + ([current] if current else [])

def extension_version(source):
    """Read only a literal named version in an actual ExtensionMeta attribute."""
    if not source.lstrip().startswith(b'<?php'):
        raise ValueError('The extension entry point must start with PHP')
    # Keep quoted strings atomic; comments cannot supply an attribute or argument.
    pattern = rb'''\s+|//[^\r\n]*|/\*.*?\*/|/\*|\#(?!\[)[^\r\n]*|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`|\#\[|[A-Za-z_\\][A-Za-z0-9_\\]*|<<<|\?>|.'''
    tokens = []
    for match in re.finditer(pattern, source, re.DOTALL):
        token = match.group()
        # An unterminated comment or string would otherwise fall back to single characters and expose its text.
        if token in (b'/*', b"'", b'"', b'`'):
            raise ValueError('Unterminated comment or string in the extension entry point')
        if token == b'?>' or (token.startswith((b'//', b'#')) and b'?>' in token):
            raise ValueError('Inline HTML is unsupported in the extension entry point')
        if token.startswith((b'"', b'`')) and b'$' in token:
            raise ValueError('Interpolated strings are unsupported in the extension entry point')
        if token.isspace() or token.startswith((b'//', b'/*')) or (token.startswith(b'#') and token != b'#['):
            continue
        # Refuse unsupported heredoc syntax rather than interpreting its body as PHP.
        if token == b'<<<':
            raise ValueError('Heredoc is unsupported in the extension entry point')
        tokens.append(token)
    versions, index = [], 0
    while index < len(tokens):
        if tokens[index] != b'#[':
            index += 1
            continue
        start, depth = index + 1, 1
        index += 1
        while index < len(tokens) and depth:
            if tokens[index] in (b'#[', b'['): depth += 1
            elif tokens[index] == b']': depth -= 1
            index += 1
        if depth:
            raise ValueError('Unclosed PHP attribute')
        for attribute in php_list(tokens[start:index-1]):
            if not attribute or attribute[0].split(b'\\')[-1] != b'ExtensionMeta':
                continue
            if attribute[1:2] != [b'('] or attribute[-1:] != [b')']:
                raise ValueError('ExtensionMeta arguments are required')
            arguments = [arg[2:] for arg in php_list(attribute[2:-1]) if arg[:2] == [b'version', b':']]
            if len(arguments) != 1 or len(arguments[0]) != 1 or not re.fullmatch(rb'''(['"])([0-9]+\.[0-9]+\.[0-9]+)\1''', arguments[0][0]):
                raise ValueError('One literal ExtensionMeta version is required')
            versions.append(arguments[0][0][1:-1].decode())
    if len(versions) != 1:
        raise ValueError('One literal ExtensionMeta version is required')
    return versions[0]

def safe_name(name):
    """One portable, relative archive path, including on case-insensitive hosts."""
    try:
        name.encode('utf-8', errors='strict')
    except UnicodeError as error:
        raise ValueError('Non-UTF-8 runtime member name') from error
    parts = name.split('/')
    if not re.fullmatch(r'[A-Za-z0-9_./-]+', name) or any(p in {'', '.', '..'} for p in parts):
        raise ValueError('Unexpected runtime member path')
    if any(p.startswith('.') or p.lower() in FORBIDDEN or ('conversion' in p.lower() and p != 'ConversionGate.php') or p.lower().startswith('convert-') for p in parts):
        raise ValueError('Development or private files cannot enter a release')
    if any(p.lower().startswith(('pin-', 'pin_', 'dev-', 'dev_')) for p in parts) or 'private' in parts[-1].lower().split('.'):
        raise ValueError('Development or private files cannot enter a release')


def runtime_directories(members):
    """Follow directory references from the entry point and native Admin discovery.

    Declarations cannot approve their own directory. Unreferenced trees (including
    mutually referencing tool trees) stay outside the runtime boundary. No PHP runs.
    """
    references = {}
    for name, data in members.items():
        if not name.endswith('.php'):
            continue
        root = name.split('/')[0]
        code = data.decode('utf-8', errors='strict').replace('\\\\', '\\')
        code = re.sub(r'namespace\s+[^;]+;', '', code)
        found = references.setdefault(root, set())
        for match in re.finditer(re.escape(PREFIX) + r'([A-Za-z_][A-Za-z0-9_]*)\\', code):
            found.add(match[1])
        for match in re.finditer(r"dirname\(__DIR__\)\s*\.\s*['\"]/([A-Za-z_][A-Za-z0-9_]*)/", code):
            found.add(match[1])
    # Paymenter's Filament provider discovers this namespace without a PHP reference.
    roots = {'Admin'} if any(name.startswith('Admin/') for name in members) else set()
    pending = ['Domains.php', *roots]
    while pending:
        for root in references.get(pending.pop(), set()):
            if root not in roots:
                roots.add(root)
                pending.append(root)
    return roots


def validate_runtime(members):
    relative = {name.removeprefix('Domains/'): data for name, data in members.items()}
    roots = runtime_directories(relative)
    present = {name.split('/')[0] for name in relative if '/' in name}
    if roots - present:
        raise ValueError('Runtime-referenced directory is missing: ' + ', '.join(sorted(roots - present)))
    for name in relative:
        safe_name(name)
        if name not in LEGAL | {'Domains.php'} and ('/' not in name or name.split('/')[0] not in roots):
            raise ValueError('Member is outside the extension runtime layout')
        if name not in LEGAL and Path(name).suffix not in ALLOWED_SUFFIXES:
            raise ValueError('Unexpected runtime file type: ' + name)
    if 'Domains.php' not in relative or 'LICENSE' not in relative:
        raise ValueError('The extension entry point and MIT license are required')
    if relative['LICENSE'] != (ROOT/'LICENSE').read_bytes():
        raise ValueError('The release license must match the repository license')
    return extension_version(relative['Domains.php'])


def runtime_members(source):
    if source.is_symlink() or not source.is_dir():
        raise ValueError('A real Domains source directory is required')
    members, seen = {}, set()
    for path in sorted(source.rglob('*')):
        relative = path.relative_to(source).as_posix()
        if path.is_symlink():
            raise ValueError('Symlinks cannot enter a release')
        safe_name(relative)
        folded = relative.casefold()
        if folded in seen:
            raise ValueError('Case-colliding runtime member')
        seen.add(folded)
        if path.is_dir():
            continue
        if not path.is_file():
            raise ValueError('Unexpected runtime member')
        # Development protocol guides are source-only, even inside provider trees.
        if path.name.lower() == 'readme.md':
            continue
        if path.stat().st_size > MAX_FILE_BYTES:
            raise ValueError('Runtime member is unexpectedly large')
        members['Domains/' + relative] = path.read_bytes()
    if sum(map(len, members.values())) > MAX_TOTAL_BYTES:
        raise ValueError('Runtime tree is unexpectedly large')
    return validate_runtime(members), members


def json_bytes(value):
    return (json.dumps(value, sort_keys=True, indent=2) + '\n').encode('utf-8')


def digests(members):
    return {name: hashlib.sha256(data).hexdigest() for name, data in members.items()}


def verify(archive, receipt=NO_RECEIPT):
    """Verify before giving an archive to the native uploader (which executes PHP)."""
    if archive.is_symlink() or archive.stat().st_size > MAX_ARCHIVE_BYTES:
        raise ValueError('Unsafe or oversized archive')
    members, seen, directories, total = {}, {}, set(), 0
    with zipfile.ZipFile(archive) as package:
        for entry in package.infolist():
            name = entry.filename
            safe_name(name)
            if not name.startswith('Domains/'):
                raise ValueError('Invalid wrapper or case-colliding member')
            prefixes = ['/'.join(name.split('/')[:n]) for n in range(1, len(name.split('/')))]
            if name in directories or any(prefix in members for prefix in prefixes):
                raise ValueError('Runtime file/directory conflict')
            directories.update(prefixes)
            for length in range(1, len(name.split('/')) + 1):
                prefix = '/'.join(name.split('/')[:length])
                previous = seen.setdefault(prefix.casefold(), prefix)
                if previous != prefix:
                    raise ValueError('Case-colliding runtime path prefix')
            if name in members:
                raise ValueError('Duplicate runtime member')
            mode = entry.external_attr >> 16
            if stat.S_IFMT(mode) != stat.S_IFREG or entry.is_dir() or entry.flag_bits & 1:
                raise ValueError('Only ordinary unencrypted runtime files are allowed')
            if entry.file_size > MAX_FILE_BYTES:
                raise ValueError('Runtime member is unexpectedly large')
            total += entry.file_size
            if total > MAX_TOTAL_BYTES:
                raise ValueError('Runtime tree is unexpectedly large')
            members[name] = package.read(entry)
    if MANIFEST not in members:
        raise ValueError('Embedded manifest is required')
    embedded_bytes = members.pop(MANIFEST)
    version = validate_runtime(members)
    expected = {'schema_version': 1, 'version': version, 'license': 'MIT', 'members': digests(members)}
    # Compare canonical bytes too: duplicate JSON keys/extra fields cannot be hidden.
    if embedded_bytes != json_bytes(expected):
        raise ValueError('Embedded manifest does not match runtime bytes')
    members[MANIFEST] = embedded_bytes
    actual = {'schema_version': 1, 'version': version, 'archive': archive.name,
              'archive_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'archive_bytes': archive.stat().st_size,
              'file_count': len(members), 'license': 'MIT', 'members': digests(dict(sorted(members.items())))}
    if receipt is not NO_RECEIPT and receipt != actual:
        raise ValueError('Release manifest does not match archive')
    return actual


def build(source, output):
    if source.is_symlink():
        raise ValueError('A real Domains source directory is required')
    source = source.resolve()
    output = output.absolute()
    if output.is_symlink() or output.resolve() != output or output.is_relative_to(source):
        raise ValueError('Use a canonical output directory outside Domains/')
    version, members = runtime_members(source)
    output.mkdir(parents=True, exist_ok=True)
    archive = output / ('paymenter-domains-' + version + '.zip')
    manifest_path = output / 'manifest.json'
    if archive.exists() or manifest_path.exists():
        raise FileExistsError('Preserve existing artifacts; choose a fresh output directory')
    # Self-hashing is impossible: the embedded inventory hashes payload files; the
    # external release receipt hashes EVERY ZIP member, including that inventory.
    members[MANIFEST] = json_bytes({'schema_version': 1, 'version': version, 'license': 'MIT', 'members': digests(members)})
    with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_STORED) as package:
        for name, data in sorted(members.items()):
            entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (stat.S_IFREG | 0o644) << 16
            entry.compress_type = zipfile.ZIP_STORED
            package.writestr(entry, data)
    manifest = verify(archive)
    if runtime_members(source) != (version, {k: v for k, v in members.items() if k != MANIFEST}):
        raise RuntimeError('Runtime changed during packaging; preserve the artifact')
    with manifest_path.open('xb') as stream:
        stream.write(json_bytes(manifest))
    verify(archive, manifest)
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir', type=Path, default=ROOT/'dist')
    parser.add_argument('--verify', type=Path, help='Verify a ZIP with its adjacent release manifest before native upload')
    args = parser.parse_args()
    if args.verify:
        manifest_path = args.verify.parent/'manifest.json'
        manifest = verify(args.verify, json.loads(manifest_path.read_bytes()))
    else:
        manifest_path = args.output_dir.absolute()/'manifest.json'
        manifest = build(ROOT/'Domains', args.output_dir.absolute())
    print(json.dumps({'complete': True, 'manifest': str(manifest_path),
                      'version': manifest['version'], 'files': manifest['file_count'], 'zip_sha256': manifest['archive_sha256']}))


if __name__ == '__main__':
    main()
