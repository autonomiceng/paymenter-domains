<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/FakeRegistrar.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestCalls,EnomTestSendLimit as Limit,EnomTestSendLimitSchema as Tables,Installation};
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
$nativeSuccessful=true;
