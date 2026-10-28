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
// Inventory arithmetic must stay exact at the integer boundary.
foreach([[PHP_INT_MAX,0],[PHP_INT_MAX-3,1]] as [$debt,$credits]){
    $large=array_replace($history,['undated_debt'=>$debt]);
    runRefuses(fn()=>Budget::previewLegacy(runPolicy('overflow',PHP_INT_MAX,PHP_INT_MAX,$fp),$large,$credits),'Overflow inventory refuses before float conversion');
}
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
// Upgrading old receipts must never normalize an invalid deployment marker.
$marker=DB::table(Tables::LOCK)->where('id',1)->value('version');
DB::table(Tables::LOCK)->where('id',1)->update(['version'=>99]);
runRefuses(fn()=>Budget::reserve($fp),'Receipt cannot repair an invalid deployment marker');
DB::table(Tables::LOCK)->where('id',1)->update(['version'=>$marker]);
// A valid older migration upgrades only on a write, preserving read-only status.
DB::table(Tables::LOCK)->where('id',1)->update(['version'=>2]);
Budget::status($policy,true);same(2,(int)DB::table(Tables::LOCK)->value('version'),'Read-only status does not upgrade old marker');
// Receipt loss must refuse both admission and consumption, including debt-only imports.
$held=Budget::reserve($fp);
same(3,(int)DB::table(Tables::LOCK)->value('version'),'Authenticated older migration retains receipt marker before sending');
$receipt=(array)DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->sole();
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->delete();
runRefuses(fn()=>Budget::reserve($fp),'Missing migrated receipt cannot admit a permit');
runRefuses(fn()=>Budget::consume($held,$fp),'Missing migrated receipt cannot consume a permit');
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->insert($receipt);
Budget::consume($held,$fp);
$p=$held;runRefuses(fn()=>Budget::consume($p),'Exactly once after migration');
config(['domains.enom_test_run_policy'=>null]);runRefuses(fn()=>Calls::reserve(Environment::Test),'Missing policy cannot restore v1 sends');
config(['domains.enom_test_run_policy'=>$policy]);runAdvance('+2 seconds');
same(6,Budget::status()['rolling_used'],'Midnight does not discard debt');
Budget::disable($policy);Budget::close($policy);runAdvance('+2 days');
same(2,Budget::status()['rolling_used'],'Dated residual expires after its date upper bound plus24h; undated debt remains');
$next=runPolicy('next',12,2,$fp);runRefuses(fn()=>Budget::prepare($next,1),'Debt survives run change and exhausts rolling cap');
$next=runPolicy('next',12,12,$fp);same(1,Budget::prepare($next,1)['remaining'],'New credits fit only alongside permanent debt');
same(2,(int)DB::table(Tables::PERMITS)->where('action','legacy')->count(),'Exact historical attempts remain permanent');
$nextReceipt=(array)DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->sole();
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->delete();
runRefuses(fn()=>Budget::status($next),'Later runs still require the retained migration receipt');
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->insert($nextReceipt);
// A receipt retains run identity even if its run row is lost.
$lostRun=(array)DB::table(Tables::RUNS)->where('run_id',$policy['run_id'])->sole();
DB::table(Tables::RUNS)->where('run_id',$policy['run_id'])->delete();
// Close the unrelated synthetic next run to isolate the receipt identity gate.
DB::table(Tables::RUNS)->where('run_id',$next['run_id'])->update(['phase'=>'closed','remaining'=>0]);
runRefuses(fn()=>Budget::prepare($policy,1),'Receipt prevents recreating its missing run with zero used');
DB::table(Tables::RUNS)->insert($lostRun);
// Continuing operations must authenticate retained proof, not just native rows.
$receipt=DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->where('id',1)->sole();
$changed=json_decode($receipt->proof,true,32,JSON_THROW_ON_ERROR);$changed['undated_debt']=0;
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->where('id',1)->update(['proof'=>json_encode($changed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);
runRefuses(fn()=>Budget::status($next),'Changed debt with original receipt hash refuses');
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->where('id',1)->update(['proof'=>$receipt->proof]);
DB::table(Tables::PERMITS)->delete();DB::table(Tables::RUNS)->delete();
DB::table(EnomTestCallSchema::TABLE)->delete();
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->delete();
DB::table(Tables::LOCK)->update(['version'=>0,'run_id'=>null]);
$debtOnly=runPolicy('debt_only',12,12,$fp);
Budget::migrateLegacy($debtOnly,['native'=>[],'attempts'=>[],'undated_debt'=>2,'source_hash'=>str_repeat('b',64)],2);
Budget::activate($debtOnly);config(['domains.enom_test_run_policy'=>$debtOnly]);
$debtReceipt=(array)DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->sole();
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->delete();
runRefuses(fn()=>Budget::reserve($fp),'Debt-only migrated run requires its receipt without historical permits');
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::TABLE)->insert($debtReceipt);
// A receipt is only written with the migrated marker, so a reset marker beside it must refuse.
$resetRuns=DB::table(Tables::RUNS)->get()->map(fn($row)=>(array)$row)->all();
DB::table(Tables::RUNS)->delete();
DB::table(Tables::LOCK)->update(['version'=>0,'run_id'=>null]);
runRefuses(fn()=>Budget::prepare(runPolicy('after_reset',12,12,$fp),1),'Receipt beside a reset marker cannot admit a new run');
DB::table(Tables::RUNS)->insert($resetRuns);
runRefuses(fn()=>Budget::status($debtOnly,true),'Receipt beside a reset marker refuses read-only status');
config(['domains.enom_test_run_policy'=>null]);
runRefuses(fn()=>Calls::reserve(Environment::Test),'Receipt beside a reset marker cannot restore daily sends');
// A retained receipt alone must prevent fallback after partial table loss.
DB::table(Tables::RUNS)->delete();
\Illuminate\Support\Facades\Schema::drop(Tables::LOCK);
config(['domains.enom_test_run_policy'=>null]);
runRefuses(fn()=>Calls::reserve(Environment::Test),'Receipt without marker or runs cannot restore daily sends');
echo "Retained v1 timestamped attempts, debt, replay and midnight migration passed\n";
$nativeSuccessful=true;
