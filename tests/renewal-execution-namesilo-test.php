<?php
// NameSilo ordinary no-ID renewals and repeated final margin; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/reconciliation-namesilo-test.php'; $nativeSuccessful = false;
use Illuminate\Support\Facades\{Cache, Queue};
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Models\{Operation, Price};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainBilling, HolderPricing, NativeDomainGuard, RenewalAcceptance, RenewalAdmission, RenewalAuthority, RenewalCompletion, RenewalExecution, RenewalWriter, WriteFeatures};
$renewBindings = new AccountBindings($registry); $authority = new RenewalAuthority($renewBindings, $clock);
$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot(); Queue::fake();
$billing = new DomainBilling($bindings, $guard, $clock); $holding = $result->domain->fresh();
$accepted = new RenewalAcceptance($binding, true, true, 25); $features = new WriteFeatures(renewal:true);
$engine = new RenewalExecution($renewBindings,$authority,new RenewalAdmission($authority,$renewBindings,$accepted,$clock),$accepted,$features,$clock);
$writes = 0;
$writer = new RenewalWriter($binding,$features,$accepted,fn ()=>$engine,static function ($origin,$command,$params,$ms) use (&$writes) {
    same(0,Illuminate\Support\Facades\DB::transactionLevel(),'NameSilo wire outside locks'); same('https://sandbox.namesilo.com/apibatch',$origin,'Fixed background test route');
    same('renewDomain',$command,'Named renewal only'); same(['domain'=>'native-history.com','years'=>1],$params,'Documented fixed annual renewal, no card/restore overrides');
    $writes++; return json_encode(['request'=>['operation'=>'renewDomain'],'reply'=>['code'=>300,'detail'=>'synthetic','domain'=>'native-history.com','order_amount'=>'8.95']]);
});
$completion = new RenewalCompletion($renewBindings,$clock);
$server = $holding->service->product->server;
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
Price::where(['server_id'=>$binding->serverId,'action'=>'renew'])->sole()->update(['wholesale'=>'8.95','revision'=>2]);
for ($cycle=0;$cycle<2;$cycle++) {
    $year = 2027+$cycle; $clock->instant = new DateTimeImmutable($year.'-09-27T12:00:00Z');
    $bill = $billing->invoice($holding->id,$user); $renewal = Operation::where('invoice_id',$bill->id)->sole();
    App\Helpers\ExtensionHelper::addPayment($bill,null,$renewal->amount); Cache::flush();
    $wire->commands=[]; $clock->instant = new DateTimeImmutable($year.'-10-16T12:00:00Z');
    if($cycle===0){ foreach([1,2,3,4] as $nth){
        $remaining=$nth; $failRead=static function($command)use(&$failRead,&$remaining,$wire){ if(--$remaining===0){ throw new RuntimeException('Synthetic transport outage'); } $wire->during=$failRead; };
        $wire->during=$failRead; same('queued',$engine->run($renewal->id,$writer)->state,'Required NameSilo read'.$nth.' outage stays retryable');
        same(0,$writes,'No write during required subread outage'); $wire->during=null; Cache::flush();
    } $wire->commands=[]; }
    same('pending',$engine->run($renewal->id,$writer)->state,'No-ID renewal acknowledged after all required reads recover');
    same(8,count($wire->commands),'Full status, margin, flags and balance repeated before send');
    same($year.'-10-18',$holding->fresh()->provider_expires_at->format('Y-m-d'),'Acknowledgement is not expiry');
    $wire->historyId=(string)(902+$cycle); $wire->expiry=($year+1).'-10-18'; $wire->historyAction='renewal'; $wire->historyDate=$year.'-10-16 05:00:01';
    Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'namesilo_renewal_v1'));
    same('namesilo_registration_v1',Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances::load($binding)->vocabulary,'Renewal acceptance preserves registration recovery');
    $clock->instant=$clock->now()->modify('+301 seconds');
    same('succeeded',$completion->run($renewal->id)->state,'Accepted renewal history and owned expiry complete no-ID renewal');
    same(null,$holding->fresh()->provider_domain_id,'No invented provider domain ID');
    same(false,array_key_exists('registration_admission',$renewal->fresh()->attempt),'Original nonpremium evidence remains on registration only');
}
same(2,$writes,'Repeated cycles send once each');
$clock->instant=new DateTimeImmutable('2029-09-27T12:00:00Z'); $bill=$billing->invoice($holding->id,$user);
$renewal=Operation::where('invoice_id',$bill->id)->sole(); App\Helpers\ExtensionHelper::addPayment($bill,null,$renewal->amount); Cache::flush();
$wire->during=static function () use ($wire) { $wire->during=static function () use ($wire) { $wire->during=static function () use ($wire) { $wire->during=static function () use ($wire) { $wire->during=static function () use ($wire) { $wire->during=static function () use ($wire) { $wire->during=static function () use ($wire) { $wire->status='Expired'; }; }; }; }; }; }; };
same('held',$engine->run($renewal->id,$writer)->state,'Final exact Active recheck refuses changed status'); same(2,$writes,'Unsafe final status sends nothing');
echo "NameSilo renewal history, repeat anchors and final Active recheck passed\n"; $nativeSuccessful=true;
