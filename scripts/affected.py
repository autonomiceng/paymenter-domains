#!/usr/bin/env python3
"""Pick the tests a change affects and split them into balanced shards.

A test is affected when it is a changed file, names one through an import, class name, include or file
mention (followed `depth` hops, from tests/affected.json), or is listed under `explicit`. A core file, or a
shared file that reaches more than a quarter of all tests within two hops, widens the slice: every test of
the suite for a core file, every test within two hops for a shared one. Widened slices are capped, keeping
the narrow slice first and then the nearest hops; the scheduled full run covers the rest.
"""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import argparse
import hashlib
import importlib.util
from fnmatch import fnmatch
import json
import math
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
MAP = ROOT/'tests/affected.json'
WIDE_DEPTH, WIDE_SHARE = 2, 0.25
CODE = ('.py', '.php', '.mjs', '.js', '.cjs', '.sh', '.ts', '.tsx', '.jsx', '.astro', '.vue', '.css', '.scss')


def git(*args):
    return subprocess.run(['git', *args], cwd=ROOT, capture_output=True, text=True, check=True).stdout


def changed_files(base):
    revision = git('rev-parse', '--verify', '--end-of-options', base+'^{commit}').strip()
    merge_base = git('merge-base', revision, 'HEAD').strip()
    return sorted(filter(None, git('diff', '--name-only', '--no-renames', merge_base, 'HEAD', '--').split('\n')))


def load_runner():
    """scripts/test.py owns the suite lists and the slow test names."""
    spec = importlib.util.spec_from_file_location('domains_test_runner', ROOT/'scripts/test.py')
    runner = importlib.util.module_from_spec(spec)
    sys.path.insert(0, str(ROOT/'scripts'))
    try:
        spec.loader.exec_module(runner)
    finally:
        sys.path.remove(str(ROOT/'scripts'))
    return runner


def is_doc(path):
    parts = Path(path).parts
    return path.endswith('.md') or ('docs' in parts and Path(path).suffix in ('.txt', '.png', '.svg'))


def is_code(path):
    name = Path(path).name
    return name.endswith(CODE) or '.' not in name


def patterns(path):
    """(imports, mentions): a user that imports the file passes it on; one that only names it is a reader."""
    name = Path(path).name
    stem = name.split('.')[0]
    parent = Path(path).parent.name
    quoted = re.escape(name)
    mention = rf'(?<![\w.\-/]){quoted}(?![\w\-])'
    if parent:
        mention += rf'|(?<![\w.\-]){re.escape(parent)}/{quoted}(?![\w\-])'
    imports = []
    if name.endswith('.py'):
        imports.append(rf'^\s*(from\s+{stem}\s+import|import\s+([\w.]+\s*,\s*)*{stem}\b)')
        imports.append(rf'import_module\(\s*[\x27"]{stem}[\x27"]')
    if name.endswith('.php') and not name.endswith('.blade.php') and stem[:1].isupper():
        imports.append(rf'(?<![\w$]){stem}(?!\w)')  # PSR-4: the file stem is the class name
    if name.endswith('.php'):
        imports.append(rf'(require|include)(_once)?\b[^;\n]*{quoted}')
    if name.endswith(('.mjs', '.js', '.cjs')):
        imports.append(rf'(\bfrom\s*|\bimport\s*\(?\s*|\brequire\(\s*)[\x27"][^\x27"]*{quoted}[\x27"]')
    if name.endswith('.sh') or '.' not in name:
        imports.append(rf'(^|[;&|]\s*)(source|\.)\s+\S*{quoted}')
    if name.endswith('.blade.php') and '/views/' in path:
        imports.append(re.escape(path.split('/views/', 1)[1][:-len('.blade.php')].replace('/', '.')))
    compiled = lambda found: re.compile('|'.join(f'(?:{p})' for p in found), re.M) if found else None
    return compiled(imports), compiled([mention])


class Index:
    """Text of every tracked file under the scan roots, read once."""

    def __init__(self, roots):
        listed = git('ls-files', '-z', '--', *[r for r in roots if (ROOT/r).exists()]).split('\0')
        self.text, self.cache = {}, {}
        for rel in filter(None, listed):
            try:
                data = (ROOT/rel).read_bytes()
            except OSError:
                continue
            if b'\0' not in data[:4096]:
                self.text[rel] = data.decode('utf-8', 'replace')

    def users(self, path):
        if path not in self.cache:
            imports, mention = patterns(path)
            key = Path(path).name.split('.')[0]
            importers, mentioners = set(), set()
            for rel, text in self.text.items():
                if rel == path or key not in text:
                    continue
                if imports and imports.search(text):
                    importers.add(rel)
                elif mention.search(text):
                    mentioners.add(rel)
            self.cache[path] = importers, mentioners
        return self.cache[path]


def levels(index, path, tests, depth, follow):
    """Tests first reached at each hop from `path`; only importers pass a change on past a reader."""
    found_by_hop, seen, frontier = [], {path}, [(path, True)]
    for _ in range(depth):
        found, ahead = set(), []
        for current, imported in frontier:
            importers, mentioners = index.users(current)
            for user, by_import in [(u, True) for u in importers if imported] + [(u, False) for u in mentioners]:
                if user in seen:
                    continue
                seen.add(user)
                if user in tests:
                    found.add(user)
                elif follow and is_code(user):
                    ahead.append((user, by_import))
        found_by_hop.append(found)
        frontier = ahead
    return found_by_hop


def spread(test):
    """A fixed order that is not alphabetical, so a capped whole-suite slice spans every feature."""
    return hashlib.sha256(test.encode()).hexdigest()


def interleave(lists, keep, out):
    """Append one test from each list in turn, nearest first, so every changed file keeps its closest tests."""
    for row in zip(*[lst + [None]*(max(map(len, lists))-len(lst)) for lst in lists]) if lists else []:
        for test in row:
            if test in keep and test not in out:
                out[test] = None


def pick(files, spec, all_tests, suite_tests):
    """Return (ranked suite tests, how many of them are affected rather than widened, notes)."""
    index = Index(spec['scan'])
    depth = spec.get('depth', 3)
    direct, near, wide, notes = [], [], [], []
    for path in files:
        if is_doc(path) or any(fnmatch(path, g) for g in spec['no_tests']):
            continue
        direct += sorted(t for glob, listed in spec['explicit'].items() if fnmatch(path, glob) for t in listed)
        if path in all_tests:
            direct.append(path)
            continue
        hops = levels(index, path, all_tests, max(depth, WIDE_DEPTH), is_code(path))
        reached = hops[:depth] if any(hops[:depth]) else [next((t for t in hops if t), set())]
        near.append([t for hop in reached for t in sorted(hop)])
        within = set().union(*hops[:WIDE_DEPTH])
        if any(fnmatch(path, g) for g in spec.get('core', [])):
            notes.append(f'{path}: core file; widened to the whole suite')
            wide.append([t for hop in hops[:WIDE_DEPTH] for t in sorted(hop)] + sorted(suite_tests - within, key=spread))
        elif len(within) > WIDE_SHARE * len(all_tests):
            notes.append(f'{path}: shared ({len(within)} of {len(all_tests)} tests within {WIDE_DEPTH} hops); widened')
            wide.append([t for hop in hops[:WIDE_DEPTH] for t in sorted(hop)])
    ranked = dict.fromkeys(t for t in direct if t in suite_tests)
    interleave(near, suite_tests, ranked)
    affected = len(ranked)
    interleave(wide, suite_tests, ranked)
    return list(ranked), affected, notes


def capped(ranked, affected, cap):
    """Keep the first `cap` ranked tests; say what the cap left out."""
    if len(ranked) <= cap:
        return sorted(ranked), None
    return sorted(ranked[:cap]), (f'capped at {cap} of {len(ranked)} ({affected} affected, {len(ranked)-affected} '
                                  f'widened), nearest first; the scheduled full run covers the other {len(ranked)-cap}')


def shards(tests, size, limit, slow_names=()):
    """Split into at most `limit` shards of about `size` tests, slow and installed tests spread first."""
    if not tests:
        return []
    count = max(1, min(limit, math.ceil(len(tests)/size)))
    slow = lambda t: Path(t).name.startswith('test_installed_') or Path(t).name in slow_names
    ordered = sorted(tests, key=lambda t: (not slow(t), spread(t)))
    split = [[] for _ in range(count)]
    for position, test in enumerate(ordered):
        split[position % count].append(test)
    return [sorted(part) for part in split]


def main():
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument('--base', help='Pick tests for changes since the merge base with this ref.')
    source.add_argument('--files', nargs='*', help='Pick tests for these changed paths.')
    source.add_argument('--all', action='store_true', help='Every test in the suite.')
    parser.add_argument('--suite', default='native')
    parser.add_argument('--cap', type=int, default=120, help='Most tests one change runs; nearest tests are kept first.')
    parser.add_argument('--shard-size', type=int, default=30)
    parser.add_argument('--max-shards', type=int, default=4)
    parser.add_argument('--github-output', type=Path, help='Append matrix=, count= and summary= lines here.')
    args = parser.parse_args()
    runner = load_runner()
    listed = runner.manifest()
    if args.suite not in listed:
        parser.error(f'unknown suite {args.suite}')
    suite_tests = set(listed[args.suite])
    all_tests = {t for paths in listed.values() for t in paths}
    if args.all:
        files, tests, notes = [], sorted(suite_tests), ['every test in the suite']
    else:
        files = sorted(set(args.files)) if args.files is not None else changed_files(args.base)
        spec = json.loads(MAP.read_text())
        ranked, affected, notes = pick(files, spec, all_tests, suite_tests)
        tests, note = capped(ranked, affected, args.cap)
        notes += [note] if note else []
    plan = shards(tests, args.shard_size, args.max_shards, runner.SLOW_NATIVE)
    summary = (f'{args.suite}: {len(files)} changed file(s) -> {len(tests)} test(s) in {len(plan)} shard(s)'
               + ''.join(f'\n- {note}' for note in notes))
    print(summary)
    for number, part in enumerate(plan, 1):
        print(f'shard {number}: ' + ' '.join(part))
    if args.github_output:
        matrix = {'include': [{'shard': f'{n}/{len(plan)}', 'tests': part} for n, part in enumerate(plan, 1)]}
        with args.github_output.open('a') as output:
            output.write(f'matrix={json.dumps(matrix, separators=(",", ":"))}\ncount={len(tests)}\n')
            output.write(f'summary<<AFFECTED_EOF\n{summary}\nAFFECTED_EOF\n')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
