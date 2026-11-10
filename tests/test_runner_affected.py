# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Affected-test selection for pull request CI: reach, widening, cap, shards, pushes and the status check."""
import importlib.util
from pathlib import Path
import subprocess
import sys
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


def run_git(root, *args):
    return subprocess.run(['git', '-C', str(root), '-c', 'user.name=t', '-c', 'user.email=t@example.com', *args],
                          check=True, capture_output=True, text=True).stdout.strip()


def commit(root, path, text):
    (root/path).parent.mkdir(parents=True, exist_ok=True)
    (root/path).write_text(text)
    run_git(root, 'add', path)
    run_git(root, 'commit', '-q', '-m', path)
    return run_git(root, 'rev-parse', 'HEAD')


class PushChanges(unittest.TestCase):
    """The workflow passes a push's `before`; the selection must see every path the push changed."""

    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.temp = Path(temp.name)
        self.root = self.temp/'repo'
        subprocess.run(['git', 'init', '-q', '-b', 'main', str(self.root)], check=True)
        self.base = commit(self.root, 'scripts/test.py', 'print("runner")\n')
        patch = mock.patch.object(affected, 'ROOT', self.root)
        patch.start()
        self.addCleanup(patch.stop)

    def test_rollback_push_sees_the_changes_it_removes(self):
        fixed = commit(self.root, 'scripts/test.py', 'print("fixed runner")\n')
        run_git(self.root, 'reset', '-q', '--hard', self.base)
        self.assertEqual(affected.changed_files(fixed), [])  # HEAD is the merge base: a merge base hides it
        self.assertEqual(affected.push_changes(fixed), ['scripts/test.py'])

    def test_force_push_to_another_history_compares_trees(self):
        run_git(self.root, 'checkout', '-q', '-b', 'old')
        old = commit(self.root, 'Domains/Old.php', '<?php\n')
        run_git(self.root, 'checkout', '-q', 'main')
        commit(self.root, 'Domains/New.php', '<?php\n')
        self.assertEqual(affected.push_changes(old), ['Domains/New.php', 'Domains/Old.php'])

    def test_force_push_fetches_the_commit_it_dropped(self):
        upstream = self.root
        dropped = commit(upstream, 'Domains/Dropped.php', '<?php\n')
        run_git(upstream, 'reset', '-q', '--hard', self.base)
        run_git(upstream, 'config', 'uploadpack.allowAnySHA1InWant', 'true')
        run_git(upstream, 'config', 'uploadpack.allowFilter', 'true')
        clone = self.temp/'clone'
        subprocess.run(['git', 'clone', '-q', upstream.as_uri(), str(clone)], check=True)
        with mock.patch.object(affected, 'ROOT', clone):
            self.assertFalse(affected.has_commit(dropped))
            self.assertEqual(affected.push_changes(dropped), ['Domains/Dropped.php'])

    def test_unknown_pushed_over_commit_fails_closed(self):
        with self.assertRaisesRegex(SystemExit, 'cannot fetch the pushed-over commit'):
            affected.push_changes('1'*40)

    def test_new_branch_compares_with_the_merge_base_of_main(self):
        run_git(self.root, 'update-ref', 'refs/remotes/origin/main', self.base)
        run_git(self.root, 'checkout', '-q', '-b', 'topic')
        commit(self.root, 'Domains/Topic.php', '<?php\n')
        run_git(self.root, 'checkout', '-q', 'main')
        main_only = commit(self.root, 'Domains/Main.php', '<?php\n')
        run_git(self.root, 'update-ref', 'refs/remotes/origin/main', main_only)
        run_git(self.root, 'checkout', '-q', 'topic')
        self.assertEqual(affected.push_changes('0'*40), ['Domains/Topic.php'])
        self.assertEqual(affected.push_changes(''), ['Domains/Topic.php'])


class StatusCheck(unittest.TestCase):
    """`Native / Status` is the one check a branch rule can require, whatever the shard count."""

    def test_verdict_passes_only_a_good_plan_with_passing_or_no_tests(self):
        self.assertEqual(affected.verdict('success', '8', 'success')[0], True)
        self.assertEqual(affected.verdict('success', '0', 'skipped')[0], True)
        for plan, count, tests in [('success', '8', 'failure'), ('success', '8', 'cancelled'),
                                   ('success', '8', 'skipped'), ('failure', '', 'skipped'),
                                   ('cancelled', '', 'skipped'), ('success', '', 'skipped'),
                                   ('success', '0', 'failure'), ('skipped', '0', 'skipped')]:
            with self.subTest(plan=plan, count=count, tests=tests):
                self.assertEqual(affected.verdict(plan, count, tests)[0], False)

    def test_verdict_command_exit_status(self):
        script = str(ROOT/'scripts/affected.py')
        run = lambda *a: subprocess.run([sys.executable, script, '--verdict', *a], capture_output=True, text=True)
        self.assertEqual(run('success', '0', 'skipped').returncode, 0)
        failed = run('success', '3', 'failure')
        self.assertEqual(failed.returncode, 1)
        self.assertIn('fail: 3 native test(s): failure', failed.stdout)

    def test_workflow_runs_status_after_every_outcome_and_fails_pipelines(self):
        workflow = (ROOT/'.github/workflows/native.yml').read_text()
        job = workflow.split('\n  status:\n', 1)[1]
        self.assertIn('name: Status', job)
        self.assertIn('needs: [plan, test]', job)
        self.assertIn('if: always()', job)
        self.assertIn('scripts/affected.py --verdict "$PLAN" "$COUNT" "$TESTS"', job)
        self.assertIn('PLAN: ${{ needs.plan.result }}', job)
        self.assertIn('TESTS: ${{ needs.test.result }}', job)
        self.assertIn('defaults:\n  run:\n    shell: bash\n', workflow)
        self.assertIn('args=(--push "$BEFORE")', workflow)
        self.assertNotIn('args=(--base "$BEFORE")', workflow)


if __name__ == '__main__':
    unittest.main()
