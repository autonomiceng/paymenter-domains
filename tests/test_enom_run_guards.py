"""Prove run-budget refusal tests detect removal of their runtime guards."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
BUDGET = 'Domains/Services/EnomTestRunBudget.php'
CORE = 'tests/enom-run-budget-test.php'
TRANSPORT = 'tests/enom-run-transports-test.php'

# Each mutation must reach a specific assertion. A syntax or fixture failure is not evidence.
MUTATIONS = [
    ('policy_hash', BUDGET, CORE,
     [("!hash_equals($policy['policy_hash'],hash('sha256',self::json($hashed)))", 'false')],
     'Expected run budget refusal: invalid canonical field policy_hash'),
    ('immutable_policy', BUDGET, CORE,
     [("$row->policy!==self::json($policy)", 'false')],
     'Expected run budget refusal: Full immutable policy mismatch ledger_dev'),
    ('active_phase', BUDGET, CORE,
     [("$row->phase!=='active'", 'false')],
     'Expected run budget refusal: No sends before authorization publication and activation'),
    ('legacy_migration', BUDGET, CORE,
     [("DB::table(EnomTestCallSchema::TABLE)->where('used','>',0)->exists()", 'false')],
     'Expected run budget refusal: old reservation-only history is not erased or rebound'),
    ('overlap', BUDGET, CORE,
     [("DB::table(EnomTestRunBudgetSchema::RUNS)->whereIn('phase',['preparing','active','disabled'])->exists()", 'false')],
     'Expected run budget refusal: Overlapping preparing generation'),
    ('run_cap', BUDGET, CORE,
     [("$used>$policy['cap']-$additional-$remaining", 'false')],
     'Expected run budget refusal: Run cap conservation'),
    ('rolling_cap', BUDGET, CORE,
     [("$rolling>$policy['rolling_cap']-$additional-$outstanding", 'false')],
     'Expected run budget refusal: Recent other-run attempt survives new run admission'),
    ('held_credit', BUDGET, CORE,
     [("(int)$row->remaining<=self::held($row)", 'false')],
     'Expected run budget refusal: Every remaining credit can hold only one permit'),
    ('permit_account', BUDGET, CORE,
     [("$fingerprint!==null && $record->account_fingerprint!==$fingerprint", 'false')],
     'Expected run budget refusal: Permit identity/action/operation mismatch'),
    ('permit_action', BUDGET, CORE,
     [("$action!==null && $record->action!==$action", 'false')],
     'Expected run budget refusal: Permit identity/action/operation mismatch'),
    ('permit_operation', BUDGET, CORE,
     [("$operationId!==null && (int)$record->operation_id!==$operationId", 'false')],
     'Expected run budget refusal: Permit identity/action/operation mismatch'),
    ('consumption_once', BUDGET, CORE,
     [("$record->attempt_time!==null", 'false'),
      ("where('permit_id',$permit)->whereNull('attempt_time')->update", "where('permit_id',$permit)->update")],
     'Expected run budget refusal: No duplicate consumption'),
    ('missing_config', BUDGET, CORE,
     [("return (int)$version===self::CONTRACT;", 'return false;')],
     'Expected run budget refusal: No legacy fallback after v2 prepared'),
    ('backward_clock', BUDGET, CORE,
     [("$now<(int)$lock->last_time", 'false')],
     'Expected run budget refusal: Backward UTC discontinuity refuses'),
    ('forward_clock', BUDGET, CORE,
     [("abs(($now-(int)$lock->last_time)-($monotonic-(int)$lock->monotonic_time))>5000000", 'false')],
     'Expected run budget refusal: Forward UTC jump without monotonic elapsed refuses'),
    ('boot_clock', BUDGET, CORE,
     [("$clockId!==$lock->clock_id", 'false')],
     'Expected run budget refusal: Different boot/clock source refuses'),
    ('account_binding', BUDGET, CORE,
     [("$row->account_fingerprint!==null && $row->account_fingerprint!==$fingerprint", 'false')],
     'Expected run budget refusal: Verified identity immutable'),
    ('bootstrap_attempt', BUDGET, CORE,
     [("DB::table(EnomTestRunBudgetSchema::PERMITS)->where('run_id',$row->run_id)->exists()", 'false')],
     'Expected run budget refusal: Unproved verification cannot be retried'),
    ('bootstrap_command', BUDGET, TRANSPORT,
     [("$command!=='GetBalance'", 'false')],
     'Expected run budget refusal: Bootstrap permits only account verification'),
    ('bootstrap_bind_before_verify', BUDGET, CORE,
     [("$row->account_fingerprint===null\n                && !DB::table", 'false\n                && !DB::table')],
     'Expected run budget refusal: Bootstrap requires consumed verification before binding'),
    ('bootstrap_native_identity', BUDGET, CORE,
     [("$row->account_fingerprint===null\n                && !Account::where", 'false\n                && !Account::where')],
     'Expected run budget refusal: Bootstrap requires saved native verified identity'),
    ('retained_lock', 'Domains/Services/EnomTestRunBudgetSchema.php', CORE,
     [("DB::table(self::LOCK)->count()!==1 || !DB::table(self::LOCK)->where('id',1)->exists()", 'false')],
     'Expected retained budget lock refusal'),
    ('financial_lock_order', 'Domains/Services/EnomTestWriteReservation.php', CORE,
     [("\\Illuminate\\Support\\Facades\\DB::transactionLevel()===0", 'true')],
     'Financial seal must keep budget lock outside financial locks'),
    ('write_preseal_active', 'Domains/Services/EnomTestWriteReservation.php', CORE,
     [("EnomTestRunBudget::assertPermit(self::$scope->day,$b->fingerprint,$action,self::$scope->id);", '')],
     'Expected run budget refusal: Disabled pre-seal write cannot prepare a physical send'),
    ('preseal_active', 'Domains/Services/EnomTestCalls.php', CORE,
     [("if(self::$purchase->runPermit){EnomTestRunBudget::assertPermit(self::$purchase->reservedDay,$binding->fingerprint,'purchase',self::$purchase->operationId);}", '')],
     'Expected run budget refusal: Disabled pre-seal purchase cannot prepare a physical send'),
]


class RunBudgetGuardRemoval(unittest.TestCase):
    def test_guard_removal_is_detected(self):
        with tempfile.TemporaryDirectory(prefix='run-guards-', dir=ROOT / '.cache') as directory:
            scratch = Path(directory)
            for name, source, test, replacements, assertion in MUTATIONS:
                with self.subTest(guard=name):
                    payload = (ROOT / source).read_text()
                    for needle, replacement in replacements:
                        self.assertEqual(payload.count(needle), 1, (name, needle))
                        payload = payload.replace(needle, replacement)
                    mutated = scratch / (name + '.php')
                    mutated.write_text(payload)
                    preload = scratch / 'preload.php'
                    preload.write_text("<?php require_once " + repr(str(ROOT / 'tests/bootstrap.php'))
                                       + "; require " + repr(str(mutated)) + ";\n")
                    result = subprocess.run([str(ROOT / 'scripts/php-test'), '-d',
                                             'auto_prepend_file=' + str(preload), test],
                                            cwd=ROOT, env=os.environ, capture_output=True, text=True, timeout=15)
                    self.assertNotEqual(result.returncode, 0, name)
                    self.assertIn(''.join(assertion.split()), ''.join((result.stdout + result.stderr).split()),
                                  name + '\n' + result.stdout + result.stderr)
        print(f'{len(MUTATIONS)} runtime guard removals reached their specific failing assertions.')


if __name__ == '__main__':
    unittest.main()
