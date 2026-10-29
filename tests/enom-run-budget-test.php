<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-run-budget.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestRunBudget as Budget,EnomTestRunBudgetSchema as Tables,EnomTestCallSchema,EnomTestCalls as Calls,EnomTestWriteReservation as Writes};
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();Tables::install();Tables::install();
$b=new AccountBinding('enom',1,Environment::Test,'synthetic');$fp=$b->fingerprint;
$policy=runPolicy(fingerprint:$fp);config(['domains.enom_test_run_policy'=>$policy]);
runRefuses(fn()=>Budget::reserve($fp),'configured policy without prepared native generation');
DB::table(EnomTestCallSchema::TABLE)->insert(['day'=>'2020-01-01','used'=>1]);
runRefuses(fn()=>Budget::prepare($policy,4),'old reservation-only history is not erased or rebound');
same(1,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Legacy evidence retained');
DB::table(EnomTestCallSchema::TABLE)->delete(); // Synthetic migration fixture only.
foreach(['version'=>1,'run_id'=>'../bad','generation'=>'','cap'=>0,'rolling_cap'=>'20','ledger_ino'=>'2','configuration_intent'=>'','policy_hash'=>str_repeat('0',64)] as $field=>$value){
    runRefuses(fn()=>Budget::prepare([$field=>$value]+$policy,4),'invalid canonical field '.$field);
}
$prepared=Budget::prepare($policy,4);same('preparing',$prepared['phase'],'Prepare never activates');
same(4,Budget::prepare($policy,4)['remaining'],'Resume never allocates again');
runRefuses(fn()=>Budget::prepare($policy,5),'Resume cannot enlarge initial allocation');
runRefuses(fn()=>Budget::reserve($fp),'No sends before authorization publication and activation');
runRefuses(fn()=>Budget::prepare(runPolicy('overlap',12,20,$fp),1),'Overlapping preparing generation');
Budget::allocate($policy,2,'run1_refresh');Budget::allocate($policy,2,'run1_refresh');
same(6,Budget::status()['remaining'],'Additional envelope is idempotent');
runRefuses(fn()=>Budget::allocate($policy,3,'run1_refresh'),'Allocation ID retains amount');
Budget::activate($policy);Budget::activate($policy);Budget::assertCapacity(6);
$permit=Budget::reserve($fp,'read');same($permit,Budget::pendingPermit(),'Host guard sees original pending permit');
same([0,6,0],array_values(array_intersect_key(Budget::status(),array_flip(['used','remaining','rolling_used']))),'Reservation does not double-count admitted credit');
runRefuses(fn()=>Budget::assertCapacity(6),'Held permits consume available capacity');
Budget::assertCapacity(5);
foreach([[$b->fingerprint,'management',null],[str_repeat('0',64),'read',null],[$fp,'read',77]] as [$account,$action,$id]){
    runRefuses(fn()=>Budget::assertPermit($permit,$account,$action,$id),'Permit identity/action/operation mismatch');
}
foreach(['ledger_dev'=>9,'ledger_ino'=>9,'ledger_uid'=>9,'ledger_gid'=>9,'cap'=>11,'rolling_cap'=>19,'generation'=>'stale','configuration_intent'=>'changed','account_fingerprint'=>str_repeat('f',64)] as $field=>$value){
    $bad=[$field=>$value]+$policy;$hash=$bad;unset($hash['policy_hash']);ksort($hash);$bad['policy_hash']=hash('sha256',json_encode($hash,JSON_THROW_ON_ERROR));
    config(['domains.enom_test_run_policy'=>$bad]);
    runRefuses(fn()=>Budget::assertPermit($permit),'Full immutable policy mismatch '.$field);
}
config(['domains.enom_test_run_policy'=>null]);runRefuses(fn()=>Calls::reserve(Environment::Test),'No legacy fallback after v2 prepared');
config(['domains.enom_test_run_policy'=>$policy]);runAdvance('+2 seconds');
Budget::assertPermit($permit,$fp,'read');Budget::consume($permit,$fp,'read');
same(null,Budget::pendingPermit(),'Consumed permit no longer pending');
same([1,5,1],[Budget::status()['used'],Budget::status()['remaining'],Budget::status()['rolling_used']],'One midnight-spanning send charged exactly once');
runRefuses(fn()=>Budget::consume($permit),'No duplicate consumption');
runRefuses(fn()=>Budget::assertPermit($permit),'Consumed permit invalid');
Calls::withPurchase($b,function()use($b,$fp):void{
    Calls::reservePurchase($b);$permit=Calls::prepareSend($b,true);runAdvance('+1 day');
    same($permit,Calls::prepareSend($b,true),'Pre-seal purchase retains its permit across midnight');
    Calls::authorizeSend($b,true,$permit);runRefuses(fn()=>Calls::authorizeSend($b,true,$permit),'No repeated purchase send');
},71);
foreach(['renewal','management'] as $action){
    Writes::run($b,$action,72,function()use($b,$action):void{
        Writes::reserve($b,$action);$permit=Writes::prepare($b,$action);
        try{DB::transaction(fn()=>same($permit,Writes::prepare($b,$action),'Financial seal checks original scope without taking budget lock'));}catch(Throwable){throw new LogicException('Financial seal must keep budget lock outside financial locks');}runAdvance('+1 day');
        same($permit,Writes::prepare($b,$action),'Pre-seal write retains its permit across midnight');
        Writes::consume($b,$action,$permit);runRefuses(fn()=>Writes::consume($b,$action,$permit),'No repeated write send');
    });
}
same(4,Calls::usage()['used'],'Run usage stays cumulative when rolling attempts age out');
same(12,Calls::usage()['limit'],'Usage reports run cap');same('run1',Calls::usage()['run_id'],'Usage exposes run identity');
runRefuses(fn()=>Budget::allocate($policy,7,'too_many'),'Run cap conservation');
runRefuses(fn()=>Budget::close($policy),'Active run must disable before closing');
Budget::disable($policy);Budget::disable($policy);
runRefuses(fn()=>Budget::reserve($fp),'Disabled generation cannot send');
runRefuses(fn()=>Budget::activate($policy),'Disabled generation cannot reactivate');
runRefuses(fn()=>Budget::prepare(runPolicy('next',10,20,$fp),1),'Disabled generation credits cannot be bypassed');
Budget::close($policy);Budget::close($policy);same(0,Budget::status()['remaining'],'Close releases unused credits only');
same(4,Budget::status()['used'],'Close retains attempts');same(4,(int)DB::table(Tables::PERMITS)->whereNotNull('attempt_time')->count(),'Permanent attempts retained');
same('closed',Budget::prepare($policy,4)['phase'],'Closed run cannot be reopened by resume');
runRefuses(fn()=>Budget::reserve($fp),'Closed run cannot send');
$next=runPolicy('next',10,2,$fp);config(['domains.enom_test_run_policy'=>$next]);
runRefuses(fn()=>Budget::prepare($next,2),'Recent other-run attempt survives new run admission');
Budget::prepare($next,1);Budget::activate($next);
$p=Budget::reserve($fp);Budget::consume($p,$fp);same(2,Budget::status()['rolling_used'],'Deployment rolling usage crosses runs');
Budget::disable($next);Budget::close($next);
$third=runPolicy('third',10,2,$fp);config(['domains.enom_test_run_policy'=>$third]);
runRefuses(fn()=>Budget::prepare($third,1),'Rolling cap exhausted despite run switch');
runAdvance('+86399 seconds');runRefuses(fn()=>Budget::prepare($third,1),'24-hour window has not expired');
runAdvance('+1 second');same(2,Budget::prepare($third,2)['remaining'],'Attempt exactly at 24-hour cutoff expires');
Budget::activate($third);$p=Budget::reserve($fp);runAdvance('+2 days');
runRefuses(fn()=>Budget::assertCapacity(2),'Unsent permit never ages out');
$clock=$runClock->instant;Budget::status();$runClock->instant=$clock->modify('-1 second');
runRefuses(fn()=>Budget::consume($p),'Backward UTC discontinuity refuses');$runClock->instant=$clock;
$runClock->instant=$clock->modify('+1 day');runRefuses(fn()=>Budget::consume($p),'Forward UTC jump without monotonic elapsed refuses');$runClock->instant=$clock;
DB::table(Tables::LOCK)->update(['clock_id'=>str_repeat('0',64)]);runRefuses(fn()=>Budget::consume($p),'Different boot/clock source refuses');
DB::table(Tables::LOCK)->update(['clock_id'=>hash('sha256',php_uname('n').'|'.trim(file_get_contents('/proc/sys/kernel/random/boot_id')))]);
DB::transaction(function()use($fp):void{try{Budget::reserve($fp);throw new LogicException('Nested transaction allowed');}catch(LogicException $e){expect(str_contains($e->getMessage(),'outside database'),'Reservation must commit independently before seal');}});
Budget::consume($p,$fp);Budget::disable($third);Budget::close($third);
$bootstrap=runPolicy('bootstrap',4,10);config(['domains.enom_test_run_policy'=>$bootstrap]);
Budget::prepare($bootstrap,4);Budget::activate($bootstrap);
\App\Models\Server::create(['id'=>1,'type'=>'server','name'=>'Synthetic run account','extension'=>'Domains','enabled'=>true]);
$saveBootstrapAccount=static fn()=>\Paymenter\Extensions\Servers\Domains\Models\Account::withVerifiedBindingWrite(static fn()=>\Paymenter\Extensions\Servers\Domains\Models\Account::create(['server_id'=>1,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic','processing_enabled'=>false]));
$saveBootstrapAccount();
runRefuses(fn()=>Budget::bindAccount($bootstrap,$fp),'Bootstrap requires consumed verification before binding');
DB::table('domains_accounts')->delete(); // Synthetic fixture separates the two binding guards.
runRefuses(fn()=>Budget::reserve($fp),'Bootstrap is not an existing account');
runRefuses(fn()=>Budget::reserve(null,'purchase',1),'Bootstrap cannot mutate');
$p=Budget::reserve();Budget::consume($p);runRefuses(fn()=>Budget::reserve(),'Unproved verification cannot be retried');
runRefuses(fn()=>Budget::bindAccount($bootstrap,$fp),'Bootstrap requires saved native verified identity');
$saveBootstrapAccount();
Budget::bindAccount($bootstrap,$fp);Budget::bindAccount($bootstrap,$fp);
runRefuses(fn()=>Budget::bindAccount($bootstrap,str_repeat('f',64)),'Verified identity immutable');
runRefuses(fn()=>Budget::reserve(),'Later stages require verified identity');
$p=Budget::reserve($fp);Budget::consume($p,$fp);
same(2,Budget::status()['used'],'Bootstrap verification and bound read use same run');
Calls::withPurchase($b,function()use($b,$fp,$bootstrap):void{
    Calls::reservePurchase($b);
    Writes::run($b,'management',100,function()use($b,$fp,$bootstrap):void{
        Writes::reserve($b,'management');
        runRefuses(fn()=>Budget::reserve($fp),'Every remaining credit can hold only one permit');
        Budget::disable($bootstrap);
        runRefuses(fn()=>Calls::prepareSend($b,true),'Disabled pre-seal purchase cannot prepare a physical send');
        runRefuses(fn()=>Writes::prepare($b,'management'),'Disabled pre-seal write cannot prepare a physical send');
    });
},99);
Budget::close($bootstrap);
// Neither normal verification nor schema installation may recreate a lost durable marker.
$lock=(array)DB::table(Tables::LOCK)->where('id',1)->sole();DB::table(Tables::LOCK)->delete();
foreach([Tables::verify(...),Tables::install(...)] as $verify){
    try{$verify();throw new LogicException('Expected retained budget lock refusal');}catch(\DomainException|\RuntimeException){}
}
config(['domains.enom_test_run_policy'=>null]);runRefuses(fn()=>Calls::reserve(Environment::Test),'Lost durable marker never restores daily sends');
DB::table(Tables::LOCK)->insert($lock);config(['domains.enom_test_run_policy'=>$bootstrap]);
// Schema retention: lost tables must never be repaired by normal installation verification.
Schema::drop(Tables::PERMITS);
try{Tables::verify();throw new LogicException('Lost permits accepted');}catch(\Illuminate\Database\QueryException|RuntimeException){}
echo "Run credits, phases, midnight permits, rolling conservation, bootstrap and refusals passed\n";
$nativeSuccessful=true;
