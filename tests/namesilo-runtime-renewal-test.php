<?php
// Synthetic installed renewal engine to physical send boundary.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{Cache,Queue};
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Operation,Price};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,DomainBilling,HolderPricing,Installation,NativeDomainGuard,RefundProof,RenewalAcceptance,RenewalAdmission,RenewalAuthority,RenewalCompletion,RenewalExecution,RenewalRetries,RenewalWriter,WriteFeatures};
expect(method_exists(RenewalWriter::class,'useReservation'),'Renewal writer needs an exact pre-seal reservation seam');
(new Installation)->install();$installedFixture=true;$fixtureEnvironment=Environment::Live;config(['app.url'=>'https://billing.example.com']);
require __DIR__.'/reconciliation-namesilo-test.php';$nativeSuccessful=false;
require __DIR__.'/Support/namesilo-write-wire.php';require_once __DIR__.'/Support/namesilo-account-guard.php';
nameSiloGuardExpected($binding,'expected@example.com');
$rb=new AccountBindings($registry);$authority=new RenewalAuthority($rb,$clock);
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock);$guard->boot();Queue::fake();
$billing=new DomainBilling($bindings,$guard,$clock);$holding=$result->domain->fresh();
$accepted=new RenewalAcceptance($binding,true,true,25);$features=new WriteFeatures(renewal:true);
$engine=new RenewalExecution($rb,$authority,new RenewalAdmission($authority,$rb,$accepted,$clock),$accepted,$features,$clock);
$transport=nsWriteTransport($binding,$clock);
$writer=new RenewalWriter($binding,$features,$accepted,fn()=>$engine,$transport->exchange());$writer->useReservation($transport->reservation);
commerceReject(fn()=>$writer->useReservation($transport->reservation));
$holding->service->product->server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
Price::where(['server_id'=>$binding->serverId,'action'=>'renew'])->sole()->update(['wholesale'=>'8.95','revision'=>2]);
$mode='success';$GLOBALS['nsReply']=static function($cmd,$p)use(&$mode):string {
    if($cmd==='contactList'){return nameSiloDefaultContactReply();}
    same('renewDomain',$cmd,'Renewal owns one named mutation');same(['domain'=>'native-history-placeholder.com','years'=>'1','auto_renew'=>'0'],$p,'Exact annual, balance-funded mapping');
    if($mode==='lost'){throw new \RuntimeException('Synthetic reply loss');}
    return json_encode(['request'=>['operation'=>$cmd],'reply'=>['code'=>300,'detail'=>'synthetic','domain'=>$p['domain'],'order_amount'=>'8.95']]);
};
for($cycle=0;$cycle<2;$cycle++){
    $year=2027+$cycle;$clock->instant=new \DateTimeImmutable($year.'-09-27T12:00:00Z');
    $bill=$billing->invoice($holding->id,$user);$op=Operation::where('invoice_id',$bill->id)->sole();\App\Helpers\ExtensionHelper::addPayment($bill,null,$op->amount);
    $clock->instant=new \DateTimeImmutable($year.'-10-16T12:00:00Z');$GLOBALS['nsOperation']=$op->id;Cache::flush();
    if($cycle===0){foreach(['cap','off'] as $refusal){
        config([$GLOBALS['nsAccountKey'].'.daily_spend_limit_usd'=>$refusal==='cap'?'0.00':'1000.00','domains.namesilo_live.renewal'=>$refusal!=='off']);
        $before=nsUsage($binding);$result=$engine->run($op->id,$writer);
        expect(!isset($result->attempt['seal']),'Local cap/Off refuses before the renewal seal');same($before['spend_micros'],nsUsage($binding)['spend_micros'],'No refused purchase spend');
        expect(!in_array('renewDomain',$GLOBALS['nsPhysical'],true),'No refused physical purchase');Cache::flush();
    }config([$GLOBALS['nsAccountKey'].'.daily_spend_limit_usd'=>'1000.00','domains.namesilo_live.renewal'=>true]);}
    $mode=$cycle===0?'success':'unsent';
    config(['domains.provider_send_guard'=>static function()use(&$mode):void {
        if($mode==='unsent'&&(Operation::findOrFail($GLOBALS['nsOperation'])->attempt['seal']??null)==='sealed'){throw new \RuntimeException('Synthetic local refusal');}
    }]);
    $money=$nativeMoney();$before=nsUsage($binding);$result=$engine->run($op->id,$writer);
    same($money,$nativeMoney(),'Renewal execution preserves native finance and jobs');same($before['spend_micros']+8950000,nsUsage($binding)['spend_micros'],'Fresh admitted wholesale spend reserved once');
    same($cycle===0?'pending':'held',$result->state,'Renewal accepted or strictly unsent');
    $sent=$GLOBALS['nsPhysical'];$engine->run($op->id,$writer);same($sent,$GLOBALS['nsPhysical'],'Original sealed renewal never replays');
    if($cycle===0){
        $wire->historyId='902';$wire->expiry='2028-10-18';$wire->historyAction='renewal';$wire->historyDate=$clock->now()->setTimezone(new \DateTimeZone('America/Los_Angeles'))->modify('+1 second')->format('Y-m-d H:i:s');
        \Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances::save(new \Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'namesilo_renewal_v1'));
        $clock->instant=$clock->now()->modify('+301 seconds');same('succeeded',(new RenewalCompletion($rb,$clock))->run($op->id)->state,'Fresh owned expiry and unique history complete renewal');
    }else{
        same('renewal_definitely_unsent',$result->safe_code,'Local unconsumed grant yields strict unsent result');RefundProof::requireUnsent($result,$holding->fresh());
    }
}
config(['domains.provider_send_guard'=>null]);$mode='lost';$clock->instant=$clock->now()->modify('+901 seconds');Cache::flush();
$retry=RenewalRetries::recover($result->id,$clock);expect($retry instanceof Operation,'Existing unsent policy creates a separately sealed replacement');
$GLOBALS['nsOperation']=$retry->id;$money=$nativeMoney();same('uncertain',$engine->run($retry->id,$writer)->state,'Consumed lost reply remains uncertain');
commerceReject(fn()=>RefundProof::requireUnsent($retry->fresh(),$holding->fresh()));
$sent=$GLOBALS['nsPhysical'];$engine->run($retry->id,$writer);same($sent,$GLOBALS['nsPhysical'],'Lost response has no replay');same($money,$nativeMoney(),'Lost response does not refund or alter invoices');
echo "Dormant admitted renewal reservation, strict unsent replacement and lost-response retention passed.\n";$nativeSuccessful=true;
