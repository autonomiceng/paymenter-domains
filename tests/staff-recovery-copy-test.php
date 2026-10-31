<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/FakeRegistrar.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,SettingsActions};
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueImportFailure,EnomTestCallLimit,EnomTestSendLimit,EnomTestSendLimitSchema,ReadFailure};
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable('2026-10-24T12:00:00Z')));
EnomTestSendLimitSchema::install();config(['domains.enom_test_sends_per_day'=>10,'domains.enom_test_sends_per_minute'=>100000]);
$problems=[];
Paymenter\Extensions\Servers\Domains\Services\FoundationSchema::install();
same('We stopped this before it reached Enom. Earlier reads may have been sent. Check API calls on the account page.',SettingsActions::refusal(new EnomTestCallLimit,'configure',['environment'=>'live']),'First Live configure refusal uses the submitted environment before an account row exists');
foreach([new DomainException('Catalogue import unavailable.'),...array_map(fn($code)=>new CatalogueImportFailure($code),ReadFailure::CODES)] as $failure){
    $copy=SettingsActions::refusal($failure,'import');
    if(str_contains($copy,'No prices changed')||!str_contains($copy,'Check saved prices')){$problems[]='Incomplete import must direct staff to saved prices without an unchanged-price guarantee';}
}
// A readable count does not prove the attempt write is available.
$account=EnomTestSendLimit::account('synthetic-uid');$sentAt=$clock->now()->getTimestamp()-10;
DB::table(EnomTestSendLimitSchema::SENDS)->insert([['account'=>$account,'command'=>'GetBalance','sent_at'=>$sentAt],['account'=>$account,'command'=>'Check','sent_at'=>$sentAt]]);
$fault=true;DB::listen(static function($query)use(&$fault):void{if($fault&&str_starts_with(strtolower($query->sql),'insert')&&str_contains($query->sql,EnomTestSendLimitSchema::SENDS)){throw new RuntimeException('Synthetic send count write failure');}});
try{EnomTestSendLimit::admit(Environment::Test,'synthetic-uid','GetBalance');throw new LogicException('A failed attempt write must refuse');}catch(EnomTestCallLimit $refusal){}
if(!str_contains($refusal->getMessage(),'Our send check stopped')||!str_contains($refusal->getMessage(),'before sending')||!str_contains($refusal->getMessage(),'counter')){$problems[]='A count write failure must say our check stopped the send before sending and name the counter';}
$copy=EnomTestSendLimit::facts('synthetic-uid');
if(str_contains($copy,'you can retry')||str_contains($copy,'Limit reached')){$problems[]='A count write failure below the limit must not promise a retry or claim the limit was reached';}
$fault=false;same(2,DB::table(EnomTestSendLimitSchema::SENDS)->count(),'Refused write rolls back its attempt');
$rows=array_fill(0,8,['account'=>$account,'command'=>'Check','sent_at'=>$sentAt]);DB::table(EnomTestSendLimitSchema::SENDS)->insert($rows);
expect(str_ends_with(EnomTestSendLimit::facts('synthetic-uid'),'Limit reached. Try again later.'),'Reaching the limit says when to retry');
DB::statement('DROP TABLE '.EnomTestSendLimitSchema::SENDS);
$copy=EnomTestSendLimit::facts('synthetic-uid');
if(!str_contains($copy,"Couldn't read our test send count")||!str_contains($copy,'no Enom test requests are sent')){$problems[]='An unreadable count must say no sends go out';}
// One label covers every unsent TEST limit refusal: a bad setting, an unreadable count or a reached limit.
// The check is ours, so the label must not read as if Enom refused the request.
foreach(['enom_test_call_cap','enom_test_transfer_call_cap','enom_test_management_call_cap','enom_test_renewal_call_cap'] as $code){
    $label=(string)Labels::reason($code);
    if(!str_starts_with($label,'Not sent: ')||str_contains($label,'reached')||str_contains($label,'refused')||!str_contains($label,' our ')){$problems[]='An unsent Enom test refusal must say our check stopped it, without claiming the limit was reached';}
}
// Background reads share the send limit: it is per login, over a rolling 24 hours and per minute.
// Every limit named here is our own safety check, not a limit Enom sets.
foreach(['write_rate_limited'=>Labels::reason('write_rate_limited'),'renewal_rate_limited'=>Labels::reason('renewal_rate_limited'),'limits'=>EnomTestSendLimit::facts(null)] as $where=>$text){
    if(!preg_match('/\bour\b/i',(string)$text)){$problems[]='Staff copy must say the limit is ours: '.$where;}
}
expect($problems===[],implode('; ',array_unique($problems)));
echo "Staff import and send count recovery copy passed.\n";$nativeSuccessful=true;
