<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/FakeRegistrar.php';
require_once __DIR__.'/Support/accounts.php';
use App\Models\Server;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{ApiCallLimits,CatalogueAcceptances,EnomLive,EnomLiveCalls,EnomLiveCallSchema,PriceSync,StaffSettingsReview as Review,EnomTestCallLimit,EnomTestCalls,EnomTestSendLimit as Limit,EnomTestSendLimitSchema as Tables,Installation};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
(new Installation)->install();(new Installation)->install();
foreach(Tables::RETIRED as $table){expect(!Schema::hasTable($table),'Fresh install has no retired TEST budget table '.$table);}
$clock=new FakeClock(new DateTimeImmutable('2026-10-28T23:59:30Z'));app()->instance(Clock::class,$clock);
function admits(string $uid,string $command='GetBalance'): bool { try{Limit::admit(Environment::Test,$uid,$command);return true;}catch(EnomTestCallLimit){return false;} }
function sends(): int { return DB::table(Tables::SENDS)->count(); }
$t0=$clock->instant;$uid='Synthetic-UID';

same(['day'=>1000,'minute'=>60],Limit::limits(),'Defaults: 1000 per rolling 24 hours, 60 per minute');
same(hash('sha256','synthetic-uid'),Limit::account($uid),'Account key is the lower-cased UID hash LIVE uses');
config(['domains.enom_test_sends_per_day'=>5,'domains.enom_test_sends_per_minute'=>3]);
$generation=EnomTestCalls::refusalGeneration();
same([true,true,true,false],[admits($uid),admits($uid),admits($uid,'Purchase'),admits($uid)],'Fourth send in one minute is refused');
expect(EnomTestCalls::refusedSince($generation),'A refusal is the typed definitely-unsent path');
same(3,sends(),'Refusals record no attempt');
same(['Purchase'],DB::table(Tables::SENDS)->where('command','Purchase')->pluck('command')->all(),'Attempts record their command');
$clock->instant=$t0->modify('+61 seconds');
same([true,true,false],[admits($uid),admits($uid),admits($uid)],'UTC midnight frees nothing; the rolling ceiling holds');
same(['day'=>5,'day_limit'=>5,'minute'=>2,'minute_limit'=>3],Limit::usage($uid),'Usage reports both windows');
expect(str_contains(Limit::facts($uid),'5 of 5'),'Facts show the rolling count');
// The existing native fixture covers saved bindings and shared counters; a unit test cannot.
fixtureAccount(1,'enom',$uid);
foreach([0=>$uid,1=>'other-uid'] as $server=>$submittedUid){
    $notice=SettingsActions::refusal(new EnomTestCallLimit,'configure',['uid'=>$submittedUid],$server);
    expect(str_contains($notice,'5 of 5')&&str_contains($notice,'2 of 3')&&str_contains($notice,'Limit reached'),
        'Connect refusal uses submitted login usage for a new account and the saved login for an existing account');
}
same(['day'=>0,'day_limit'=>5,'minute'=>0,'minute_limit'=>3],Limit::usage('other-uid'),'Accounts are counted apart');
same([true,true],[admits('OTHER-UID'),admits('other-uid')],'Another login has its own limit');
same(2,Limit::usage('Other-Uid')['day'],'UID case aliases share one limit');
$clock->instant=$t0->modify('+86399 seconds');same(false,admits($uid),'Sends from 24 hours ago still count one second early');
$clock->instant=$t0->modify('+86400 seconds');same(true,admits($uid),'Sends leave the window after exactly 24 hours');
$clock->instant=$t0->modify('-1 hour');same(false,admits($uid),'A clock stepped back keeps newer attempts counted');
$clock->instant=$t0->modify('+3 days');same(true,admits($uid),'Old attempts expire');
same(9,sends(),'Attempts are never deleted or refunded');
foreach([0,'5',-1,100001,1.5,null,true] as $bad){
    foreach(['domains.enom_test_sends_per_day','domains.enom_test_sends_per_minute'] as $key){
        $saved=config($key);config([$key=>$bad]);$before=sends();
        same(false,admits('fresh-uid'),'Setting '.$key.'='.var_export($bad,true).' refuses');same($before,sends(),'Refused setting records nothing');
        expect(str_contains(Limit::facts('fresh-uid'),$bad===0?'is 0':'invalid'),'Facts explain the setting '.var_export($bad,true));
        config([$key=>$saved]);
    }
}
config(['domains.enom_test_sends_per_day'=>100000,'domains.enom_test_sends_per_minute'=>100000]);same(true,admits('fresh-uid'),'Upper bound is allowed');
config(['domains.enom_test_sends_per_day'=>5,'domains.enom_test_sends_per_minute'=>3]);
$before=sends();Limit::admit(Environment::Live,$uid,'Purchase');same($before,sends(),'LIVE sends are not counted here');
DB::transaction(function(){same(false,admits('fresh-uid'),'Refused inside an outer transaction, so a rollback cannot refund it');});
same($before,sends(),'Outer-transaction refusal records nothing');
Schema::rename(Tables::SENDS,'domains_enom_test_sends_moved');same(false,admits('fresh-uid'),'A database error refuses');
expect(str_contains(Limit::facts('fresh-uid'),"Couldn't read"),'Facts report an unreadable count');
Schema::rename('domains_enom_test_sends_moved',Tables::SENDS);same($before,sends(),'Database error recorded nothing');
same(false,admits(''),'An empty UID refuses');
Tables::verify();(new Installation)->install();same($before,sends(),'Reinstall keeps every attempt');
echo "Enom TEST send limit: rolling 24 hours, per minute, accounts, settings, LIVE, transactions and errors passed.\n";

// Native fixture is required: reviewed native settings, permissions, shared login counts and atomic audit.
require_once __DIR__.'/Support/staff-tlds.php';
$staff=staffUser(['admin.domains.view','admin.domains.manage-policy',Review::permission('configure')]);
$server=Server::findOrFail(1);$account=Account::where('server_id',1)->sole();
$binding=CatalogueAcceptances::binding($account);
$review=static function(?string $stamp=null)use($staff): Review {
    return new Review($staff->id,1,null,'call_limits',Review::ticket($staff->id,1,null,'call_limits',$stamp??Review::stamp(1,null)),'Adjust our runaway guard');
};
$write=static fn($r,$day,$minute=3)=>ApiCallLimits::save($r,['day'=>$day,'minute'=>$minute]);
same(true,admits($uid),'Seed current usage before lowering the guard');
$before=PriceSync::callsToday($binding);
CatalogueAcceptances::save($binding,0,['com'=>['register'=>[1],'renew'=>[1],'transfer'=>[1],'registry'=>false,'renewal_safe'=>true]]);
DB::table('domains_observation_queues')->insert(['server_id'=>1,'account_fingerprint'=>$binding->fingerprint,'catalogue_hold'=>'catalogue_call_capacity']);
$stale=$review();$changed=$review();$write($changed,2,2);
same(['used'=>$before['used'],'limit'=>2],PriceSync::callsToday($binding),'Sync capacity sees the saved cap immediately');
same(['day'=>2,'minute'=>2],Limit::limits($uid),'Physical guard reads the same saved limits');
expect(str_contains(\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncFailure(1)['cause'],'0 calls left'),'The capacity check immediately shows the reduced allowance');
same(false,admits($uid),'Lowering below usage refuses further sends');
same(5,Limit::usage('other-uid')['day_limit'],'Another login keeps the config default');
same(2,Limit::usage(strtoupper($uid))['day_limit'],'UID case aliases share the saved cap');
tldRefusal(fn()=>$write($stale,10),'Settings changed. Reload this page.');
tldRefusal(fn()=>$write($changed,10),'Settings changed. Reload this page.');
$rows=Review::historyRows($server);$audit=end($rows);
same($staff->id,$audit['actor_id'],'Switch log records the staff member');expect(isset($audit['at']),'Switch log records when');
same(['day'=>5,'minute'=>3],$audit['before']['call_limits'],'Switch log records old effective limits');
same(['day'=>2,'minute'=>2],$audit['after']['call_limits'],'Switch log records new limits');
foreach([0,-1,100001,'1.5',true,'1e3'] as $bad){
    try{$write($review(),$bad);expect(false,'Invalid cap accepted');}catch(DomainException){}
    same(2,Limit::usage($uid)['day_limit'],'Invalid edit leaves the guard intact');
}
$denied=$review();$staff->role->update(['permissions'=>['admin.domains.view','admin.domains.manage-policy']]);
tldRefusal(fn()=>$write($denied,10),'Current staff permissions required.');
same(2,Limit::usage($uid)['day_limit'],'Revoked settings permission refuses the edit');

// The same override feeds Live readers and its atomic reservation guard, without touching reserved spend.
$staff->role->update(['permissions'=>['admin.domains.view','admin.domains.manage-policy',Review::permission('configure')]]);
$write($review(),100,3);
expect(str_contains(\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncFailure(1)['cause'],'98 calls left'),'Raising the cap immediately updates the failed sync check without claiming a successful sync');
// A saved sell rule reserves 70% of the day for other work; daily room alone cannot promise an automatic retry.
$server->settings()->create(['key'=>\Paymenter\Extensions\Servers\Domains\Services\SellRule::KEY,'type'=>'array','encrypted'=>false,
    'value'=>['mode'=>'except','max_cost'=>null,'years'=>1,'transfers'=>false]]);
DB::table(Tables::SENDS)->insert(array_fill(0,28,['account'=>Limit::account($uid),'command'=>'GetBalance','sent_at'=>$clock->now()->getTimestamp()]));
same(70,PriceSync::callsLeft($binding),'Daily cap still has room at the automatic allowance threshold');
same(0,PriceSync::backgroundLeft($binding,$clock),'Automatic price reads have no room at thirty percent used');
same('Price sync needs 6 calls; 0 calls left for automatic price reads',
    \Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncFailure(1)['cause'],
    'A retained capacity failure shows the automatic allowance without promising a retry');
config(['app.url'=>'https://billing.example.test','domains.enom_live.site'=>'https://billing.example.test','domains.enom_identity.environment'=>'live']);
$liveServer=Server::create(['name'=>'Synthetic live cap','type'=>'server','extension'=>'Domains','enabled'=>true]);
$live=Account::withVerifiedBindingWrite(static fn()=>Account::create(['server_id'=>$liveServer->id,'registrar_key'=>'enom','environment'=>'live','stable_identity'=>'live-cap-uid']));
Account::withVerifiedBindingWrite(fn()=>$live->update(['live_site'=>'https://billing.example.test']));
$liveBinding=CatalogueAcceptances::binding($live);
config(['domains.enom_live.accounts'=>['live-cap-uid'=>['fingerprint'=>$liveBinding->fingerprint,'daily_call_cap'=>6,'daily_spend_limit_usd'=>'25.00']]]);
EnomLiveCalls::reserve($liveBinding);
EnomLiveCalls::reserve($liveBinding);
$liveReview=new Review($staff->id,$liveServer->id,null,'call_limits',Review::ticket($staff->id,$liveServer->id,null,'call_limits',Review::stamp($liveServer->id,null)),'Adjust our Live runaway guard');
$write($liveReview,1);
same(['used'=>2,'limit'=>1],PriceSync::callsToday($liveBinding),'Live sync capacity uses the same saved cap immediately');
try{EnomLiveCalls::reserve($liveBinding);expect(false,'Live send exceeded lowered cap');}catch(EnomTestCallLimit){}
same('25.00',EnomLive::account($liveBinding)['daily_spend_limit_usd'],'Daily spend is unchanged');
same(2,(int)DB::table(EnomLiveCallSchema::TABLE)->value('used'),'Lowering never resets reservations');
echo "Reviewed per-login call cap writes, stale pages, audit and TEST/Live sync/send readers passed.\n";
$nativeSuccessful=true;
