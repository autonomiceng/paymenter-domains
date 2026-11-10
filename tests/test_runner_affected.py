# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Affected-test selection for pull request CI: reach, widening, cap and shards."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('domains_affected', ROOT/'scripts/affected.py')
affected = importlib.util.module_from_spec(spec); spec.loader.exec_module(affected)

FILES = {
    'Domains/Services/Clock.php': '<?php\nfinal class Clock {}\n',
    'Domains/Services/Billing.php': '<?php\nfinal class Billing { public function __construct(private Clock $clock) {} }\n',
    'tests/clock-test.php': '<?php\n$clock = new Clock();\n',
    'tests/billing-test.php': '<?php\n$billing = new Billing(new Clock());\n',
    'scripts/test.py': 'print("runner")\n',
    'README.md': 'Clock.php and Billing.php\n',
    **{f'tests/other-{n}-test.php': '<?php\n' for n in range(7)},
}
MAP = {'scan': ['Domains', 'tests', 'scripts'], 'depth': 1, 'core': ['scripts/test.py'],
       'explicit': {'Domains/Services/Billing.php': ['tests/other-0-test.php']}, 'no_tests': ['*.md']}


class AffectedTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        for name, text in FILES.items():
            (self.root/name).parent.mkdir(parents=True, exist_ok=True)
            (self.root/name).write_text(text)
        subprocess.run(['git', 'init', '-q', str(self.root)], check=True)
        subprocess.run(['git', '-C', str(self.root), 'add', '.'], check=True)
        patch = mock.patch.object(affected, 'ROOT', self.root)
        patch.start()
        self.addCleanup(patch.stop)
        self.tests = {name for name in FILES if name.startswith('tests/')}

    def pick(self, *files, tests=None):
        return affected.pick(list(files), MAP, self.tests, tests or self.tests)

    def test_class_users_within_depth_and_explicit_tests_are_affected(self):
        self.assertEqual(self.pick('Domains/Services/Clock.php'), (['tests/billing-test.php', 'tests/clock-test.php'], 2, []))
        self.assertEqual(self.pick('Domains/Services/Billing.php'),
                         (['tests/other-0-test.php', 'tests/billing-test.php'], 2, []))

    def test_changed_test_runs_itself_and_docs_run_nothing(self):
        self.assertEqual(self.pick('tests/other-3-test.php'), (['tests/other-3-test.php'], 1, []))
        self.assertEqual(self.pick('README.md'), ([], 0, []))

    def test_core_file_widens_to_the_whole_suite(self):
        ranked, count, notes = self.pick('scripts/test.py')
        self.assertEqual((sorted(ranked), count), (sorted(self.tests), 0))
        self.assertEqual(notes, ['scripts/test.py: core file; widened to the whole suite'])

    def test_shared_file_widens_to_two_hops(self):
        (self.root/'tests/other-1-test.php').write_text('<?php\n$billing = new Billing(new Clock());\n')
        (self.root/'tests/other-2-test.php').write_text('<?php\nnew Billing();\n')
        ranked, count, notes = self.pick('Domains/Services/Clock.php')
        self.assertEqual(ranked[:count], ['tests/billing-test.php', 'tests/clock-test.php', 'tests/other-1-test.php'])
        self.assertEqual(ranked[count:], ['tests/other-2-test.php'])
        self.assertIn('shared (4 of 9 tests within 2 hops); widened', notes[0])

    def test_cap_keeps_the_nearest_tests_and_says_what_it_left(self):
        self.assertEqual(affected.capped(['b', 'a'], 1, 5), (['a', 'b'], None))
        tests, note = affected.capped(['c', 'a', 'b', 'd'], 1, 2)
        self.assertEqual(tests, ['a', 'c'])
        self.assertIn('capped at 2 of 4 (1 affected, 3 widened)', note)
        self.assertIn('covers the other 2', note)

    def test_shards_hold_each_test_once_and_spread_slow_tests(self):
        tests = [f'tests/t{n}-test.php' for n in range(10)] + ['tests/test_installed_a.py', 'tests/slow-test.php']
        plan = affected.shards(tests, 4, 3, {'slow-test.php'})
        self.assertEqual(len(plan), 3)
        self.assertEqual(sorted(t for part in plan for t in part), sorted(tests))
        self.assertEqual([len(part) for part in plan], [4, 4, 4])
        slow = [sum(t in ('tests/test_installed_a.py', 'tests/slow-test.php') for t in part) for part in plan]
        self.assertEqual(sorted(slow), [0, 1, 1])
        self.assertEqual(affected.shards(tests, 100, 3), [sorted(tests)])
        self.assertEqual(affected.shards([], 4, 3), [])


if __name__ == '__main__':
    unittest.main()
