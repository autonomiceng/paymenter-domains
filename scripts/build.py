#!/usr/bin/env python3
"""Package boundary support. Current foundation has no entry point and cannot be released."""
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
RUNTIME_DIRECTORIES = {'Contracts', 'Data', 'Registrars', 'Admin', 'Http', 'Jobs', 'Models', 'Policies', 'Services', 'database', 'resources', 'routes'}

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
    pattern = rb'''\s+|//[^\r\n]*|/\*.*?\*/|\#(?!\[)[^\r\n]*|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`|\#\[|[A-Za-z_\\][A-Za-z0-9_\\]*|<<<|\?>|.'''
    tokens = []
    for match in re.finditer(pattern, source, re.DOTALL):
        token = match.group()
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

def runtime_members(source):
    if source.is_symlink() or not source.is_dir():
        raise ValueError('A real Domains source directory is required')
    members = {}
    for path in sorted(source.rglob('*')):
        relative = path.relative_to(source).as_posix()
        if path.is_symlink():
            raise ValueError('Symlinks cannot enter a release')
        if path.is_dir():
            continue
        if not path.is_file() or not re.fullmatch(r'[A-Za-z0-9_./-]+', relative):
            raise ValueError('Unexpected runtime member')
        parts = path.relative_to(source).parts
        if relative not in {'LICENSE', 'Domains.php'} and (len(parts) < 2 or parts[0] not in RUNTIME_DIRECTORIES):
            raise ValueError('Member is outside the extension runtime layout')
        if any(part.startswith('.') or part.lower() in {'tests', 'docs', 'private', 'node_modules', 'vendor'} for part in path.relative_to(source).parts):
            raise ValueError('Development or private files cannot enter a release')
        if 'private' in path.name.lower().split('.'):
            raise ValueError('Private filenames cannot enter a release')
        if relative != 'LICENSE' and path.suffix not in ALLOWED_SUFFIXES:
            raise ValueError('Unexpected runtime file type: ' + relative)
        if path.stat().st_size > 1048576:
            raise ValueError('Runtime member is unexpectedly large')
        members['Domains/' + relative] = path.read_bytes()
    if 'Domains/Domains.php' not in members or 'Domains/LICENSE' not in members:
        raise ValueError('The extension entry point and MIT license are required')
    if members['Domains/LICENSE'] != (ROOT/'LICENSE').read_bytes():
        raise ValueError('The release license must match the repository license')
    return extension_version(members['Domains/Domains.php']), members

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
    with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as package:
        for name, data in members.items():
            entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (stat.S_IFREG | 0o644) << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            package.writestr(entry, data, compresslevel=9)
    if archive.stat().st_size > 10_000_000:
        raise ValueError('Native upload size limit exceeded; preserve the partial artifact')
    # Verify every actual member and source byte after writing.
    with zipfile.ZipFile(archive) as package:
        if package.namelist() != list(members) or any(package.read(name) != data for name, data in members.items()):
            raise RuntimeError('Archive readback failed')
    if runtime_members(source) != (version, members):
        raise RuntimeError('Runtime changed during packaging; preserve the artifact')
    manifest = {'schema_version': 1, 'version': version, 'archive': archive.name,
                'archive_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'archive_bytes': archive.stat().st_size,
                'file_count': len(members), 'license': 'MIT', 'members': {name: hashlib.sha256(data).hexdigest() for name, data in members.items()}}
    with manifest_path.open('x') as stream:
        json.dump(manifest, stream, sort_keys=True, indent=2); stream.write('\n')
    return manifest

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir', type=Path, default=ROOT/'dist')
    args = parser.parse_args()
    manifest = build(ROOT/'Domains', args.output_dir.absolute())
    print(json.dumps({'complete': True, 'manifest': str(args.output_dir.absolute()/'manifest.json'),
                      'version': manifest['version'], 'files': manifest['file_count'], 'zip_sha256': manifest['archive_sha256']}))

if __name__ == '__main__':
    main()
