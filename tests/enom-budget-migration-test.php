<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-run-budget.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{Installation,EnomTestRunBudget as Budget,EnomTestRunBudgetSchema as Tables,EnomTestCallSchema,EnomTestCalls as Calls};
(new Installation)->install();
\App\Models\Server::create(['id'=>1,'type'=>'server','name'=>'Synthetic migration','extension'=>'Domains','enabled'=>true]);
Account::withVerifiedBindingWrite(static fn()=>Account::create(['server_id'=>1,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic','processing_enabled'=>false]));
$fp=(new AccountBinding('enom',1,Environment::Test,'synthetic'))->fingerprint;
$policy=runPolicy('retained',12,12,$fp);
DB::table(EnomTestCallSchema::TABLE)->insert(['day'=>'2026-10-27','used'=>3]);
$history=['native'=>[['day'=>'2026-10-27','used'=>3]],'attempts'=>[1793145599000000,1793145598000000],
    'undated_debt'=>2,'source_hash'=>str_repeat('a',64)];
$before=serialize([DB::table(Tables::LOCK)->get()->all(),DB::table(Tables::RUNS)->get()->all(),DB::table(Tables::PERMITS)->get()->all(),DB::table(EnomTestCallSchema::TABLE)->get()->all()]);
$preview=Budget::previewLegacy($policy,$history,8);
same([5,5,0,13,13,1,1],[$preview['native_used'],$preview['rolling_used'],$preview['outstanding_credits'],$preview['native_required'],$preview['rolling_required'],$preview['native_short'],$preview['rolling_short']],'Read-only exact shortage inventory');
same($before,serialize([DB::table(Tables::LOCK)->get()->all(),DB::table(Tables::RUNS)->get()->all(),DB::table(Tables::PERMITS)->get()->all(),DB::table(EnomTestCallSchema::TABLE)->get()->all()]),'Preflight issues no credits/permits or clock writes');
$rolling=Budget::previewLegacy(runPolicy('retained',900,4,$fp),$history,8);
same([0,9],[$rolling['native_short'],$rolling['rolling_short']],'Read-only rolling shortage is independent of run cap');
// Existing source refuses used v1 state. This retained-state path must not erase it.
runRefuses(fn()=>Budget::prepare($policy,4),'ordinary prepare still cannot silently adopt used history');
runRefuses(fn()=>Budget::migrateLegacy($policy,$history,8),'Native cap exhaustion leaves no partial import');
runRefuses(fn()=>Budget::migrateLegacy(runPolicy('retained',12,12,str_repeat('0',64)),$history,4),'Account proof mismatch');
runRefuses(fn()=>Budget::migrateLegacy($policy,array_replace($history,['native'=>[]]),4),'Native snapshot mismatch');
$s=Budget::migrateLegacy($policy,$history,4);
same([5,4,5],[$s['used'],$s['remaining'],$s['rolling_used']],'Two attempts plus three conservative debt units');
same(3,$s['legacy_debt'],'Dated native residual plus undated old-log debt retained');
same(3,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Original daily evidence retained');
same($s,Budget::migrateLegacy($policy,$history,4),'Replay does not issue credits twice');
runRefuses(fn()=>Budget::migrateLegacy($policy, array_replace($history,['undated_debt'=>1]),4),'Proof cannot change');
runRefuses(fn()=>Budget::migrateLegacy(runPolicy('other',12,12,$fp),$history,4),'Cannot reimport into a second run');
Budget::activate($policy);config(['domains.enom_test_run_policy'=>$policy]);
$before=DB::table(Tables::LOCK)->get()->toJson();Budget::status($policy,true);same($history['source_hash'],Budget::legacySourceHash($policy),'Read-only source authentication');same($before,DB::table(Tables::LOCK)->get()->toJson(),'Status/source reads preserve native clock revision');
$p=Budget::reserve($fp);Budget::consume($p,$fp);runRefuses(fn()=>Budget::consume($p),'Exactly once after migration');
config(['domains.enom_test_run_policy'=>null]);runRefuses(fn()=>Calls::reserve(Environment::Test),'Missing policy cannot restore v1 sends');
config(['domains.enom_test_run_policy'=>$policy]);runAdvance('+2 seconds');
same(6,Budget::status()['rolling_used'],'Midnight does not discard debt');
Budget::disable($policy);Budget::close($policy);runAdvance('+2 days');
same(2,Budget::status()['rolling_used'],'Dated residual expires after its date upper bound plus24h; undated debt remains');
$next=runPolicy('next',12,2,$fp);runRefuses(fn()=>Budget::prepare($next,1),'Debt survives run change and exhausts rolling cap');
$next=runPolicy('next',12,12,$fp);same(1,Budget::prepare($next,1)['remaining'],'New credits fit only alongside permanent debt');
same(2,(int)DB::table(Tables::PERMITS)->where('action','legacy')->count(),'Exact historical attempts remain permanent');
echo "Retained v1 timestamped attempts, debt, replay and midnight migration passed\n";
$nativeSuccessful=true;
