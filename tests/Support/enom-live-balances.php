<?php
// The installed fixture proves scheduler/factory wiring, durable budgets and native staff alerts together.
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic cURL only; no sockets.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, BalanceSchema, RegistrarBalances, RuntimeWork};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
(static function()use($a,$binding,$bindings,$clock,$staff,$server,$accountConfig):void {
    Auth::setUser($staff);
    $clock->at=new DateTimeImmutable('2028-10-01T12:00:00Z');
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $bindings->setProcessing($server->id,false);Queue::fake();
    installedRenewalFacts($binding,$clock->now(),$clock->now()->modify('+30 days'));
    config(['domains.enom_live.management'=>false]);
    // Other producers have their own schedules; keep this turn focused on balance refreshes.
    DB::table('domains_observation_queues')->where('server_id',$server->id)->update(['catalogue_due'=>$clock->now()->getTimestamp()+86400]);
    DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$server->id],['account_fingerprint'=>$binding->fingerprint,'offer_feed_tried_at'=>$clock->now()->getTimestamp(),'offer_work'=>json_encode(['feed_due'=>$clock->now()->getTimestamp()+86400,'credential_revision'=>$bindings->offerCredentialRevision($binding),'upgrade_generation'=>\Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::generation()])]);
    $balance=static fn()=>DB::table(BalanceSchema::TABLE)->where('server_id',$server->id)->sole();
    $balances=app(RegistrarBalances::class);$worker=app(RuntimeWork::class);
    resetWire(['reply'=>static function(array $p):string {
        same('GetBalance',$p['Command'],'The scheduled balance turn reads only balance');
        same(0,DB::transactionLevel(),'Live balance is read outside locks');
        same('https://reseller.enom.com/interface.asp',$GLOBALS['enomWire']['options'][CURLOPT_URL],'Reviewed Live endpoint');
        return '<interface-response><Command>GetBalance</Command><ErrCount>0</ErrCount><Done>true</Done><AvailableBalance>0.01</AvailableBalance></interface-response>';
    }]);
    $worker->tickOperations();same([],$GLOBALS['enomWire']['forms'],'Operations-only ticks never read balances');
    DB::table('domains_runtime_turns')->where('server_id',$server->id)->update(['due_at'=>0,'domain_cursor'=>(int)DB::table('domains_domains')->max('id')]);
    $worker->tick();
    same(['GetBalance'],array_column($GLOBALS['enomWire']['forms'],'Command'),'Enabled Live accounts receive their daily balance read: '.json_encode([array_column($GLOBALS['enomWire']['forms'],'Command'),$balance()->last_error_code]));
    config(['domains.enom_live.registration'=>false,'domains.enom_live.renewal'=>false]);
    same('daily',$balance()->source,'Scheduled read records daily source');
    same('0.01',$balance()->amount,'Daily Live balance is persisted');
    expect(RegistrarBalances::facts($a->fresh(),$clock->now())['low'],'Live forecast reports low funds while orders are paused');
    same('2028-10-01',$balance()->notified_on,'Scheduled Live low funds reaches the native staff inbox');
    same('rate_limited',$balances->refresh($server->id),'Manual and daily balance refreshes share the cooldown');
    foreach([RegistrarSetup::class,RegistrarAccount::class] as $page){
        $args=$page===RegistrarAccount::class?['account'=>$server->id]:[];
        $view=Livewire\Livewire::actingAs($staff)->test($page,$args)->assertSee('Low balance');$view->call('$refresh');
    }
    same(1,count($GLOBALS['enomWire']['forms']),'Page loads and refreshes read the database only');
    $clock->at=$clock->at->modify('+61 seconds');
    $lease=app(AccountReadBudget::class)->acquire($binding,RequestOrigin::Interactive);
    try{same('rate_limited',$balances->refresh($server->id),'Manual Live read obeys the shared account lease');}
    finally{app(AccountReadBudget::class)->release($binding,RequestOrigin::Interactive,$lease);}
    same(1,count($GLOBALS['enomWire']['forms']),'Busy account adds no provider read');
    // Daily deferral retries in a minute without replacing the retained good balance.
    $clock->at=$clock->at->modify('+61 seconds');
    DB::table(BalanceSchema::TABLE)->where('server_id',$server->id)->update(['daily_due_at'=>0]);
    $lease=app(AccountReadBudget::class)->acquire($binding,RequestOrigin::Interactive);
    try{$balances->daily($a->fresh());}finally{app(AccountReadBudget::class)->release($binding,RequestOrigin::Interactive,$lease);}
    same($clock->now()->getTimestamp()+60,(int)$balance()->daily_due_at,'Deferred Live schedule retries next minute');
    same(null,$balance()->last_error_code,'Deferred read retains successful balance');
    $clock->at=$clock->at->modify('+61 seconds');$balances->daily($a->fresh());
    same(2,count($GLOBALS['enomWire']['forms']),'Live daily read recovers after account capacity returns');
    same(2,(int)DB::table(Paymenter\Extensions\Servers\Domains\Services\EnomLiveCallSchema::TABLE)->where('day','2028-10-01')->value('used'),'Physical reads consume our daily call cap');
    same(0,(int)DB::table(Paymenter\Extensions\Servers\Domains\Services\EnomLiveCallSchema::TABLE)->where('day','2028-10-01')->value('spend_micros'),'Balance reads reserve no spending');
    $balances->daily($a->fresh());same(2,count($GLOBALS['enomWire']['forms']),'Successful daily read is coalesced');
    config(['domains.enom_live.catalogue'=>false]);
    DB::table(BalanceSchema::TABLE)->where('server_id',$server->id)->update(['daily_due_at'=>0]);
    $clock->at=$clock->at->modify('+61 seconds');$balances->daily($a->fresh());
    same(2,count($GLOBALS['enomWire']['forms']),'Live read opt-out sends nothing');
    config(['domains.enom_live.catalogue'=>true,'domains.enom_live.accounts'=>['live-uid'=>[...$accountConfig,'daily_call_cap'=>2]]]);
    $balances->daily($a->fresh());same(2,count($GLOBALS['enomWire']['forms']),'Our exhausted Live daily cap refuses before transport');
    same('0.01',$balance()->amount,'Our cap refusal retains the last balance');
    config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);
})();
