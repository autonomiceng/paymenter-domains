# MIT, Copyright (c) 2026 Autonomic Engineering.
"""A Domains directory holds at most 40 PHP files of its own; subdirectories count separately.

OVERSIZED records the directories already above the limit at their current counts. A count may only go down:
lower the entry when files leave, and drop it once the directory is back within the limit. Raising an entry or
adding one to make this test pass is a review block. Put new code in the owning feature folder from
docs/design/layout.md.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
LIMIT = 40
OVERSIZED = {
    'Domains/Admin/Support': 74,
    'Domains/Data': 61,
    'Domains/Services': 310,
    'Domains/resources/views/partials': 49,
}


def counts():
    found = {}
    for path in (ROOT/'Domains').rglob('*.php'):
        directory = str(path.parent.relative_to(ROOT))
        found[directory] = found.get(directory, 0)+1
    return found


class DirectorySizes(unittest.TestCase):
    def test_no_directory_grows_past_its_limit(self):
        over = [f'{d}: {n} PHP files, limit {OVERSIZED.get(d, LIMIT)}'
                for d, n in sorted(counts().items()) if n > OVERSIZED.get(d, LIMIT)]
        self.assertEqual([], over, 'Move code into its feature folder (docs/design/layout.md); never raise OVERSIZED:\n' + '\n'.join(over))

    def test_oversized_counts_only_go_down(self):
        found = counts()
        lower = [f'{d}: set {found.get(d, 0)} (was {n})' if found.get(d, 0) > LIMIT else f'{d}: remove the entry'
                 for d, n in sorted(OVERSIZED.items()) if found.get(d, 0) < n]
        self.assertEqual([], lower, 'Lower OVERSIZED in tests/test_directory_sizes.py:\n' + '\n'.join(lower))


if __name__ == '__main__':
    unittest.main()
