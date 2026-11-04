<?php
// Synthetic sent renewal support recovery; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/reconciliation-namesilo-test.php';$nativeSuccessful=false;
require_once __DIR__.'/Support/namesilo-account-guard.php';
use Illuminate\Support\Facades\{Cache,Queue};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Operation,Price};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,DomainBilling,EnomAnswers,HolderPricing,NativeDomainGuard,RenewalAcceptance,RenewalAdmission,RenewalAuthority,RenewalCompletion,RenewalExecution,RenewalWriter,StaffSettingsReview as Review,StaffSettingsWrites,WriteFeatures};
nameSiloGuardExpected($binding,'expected@example.com');
$renewBindings=new AccountBindings($registry);$authority=new RenewalAuthority($renewBindings,$clock,offers: $GLOBALS['caps']??null);
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock);$guard->boot();Queue::fake();
$billing=new DomainBilling($bindings,$guard,$clock);$holding=$result->domain->fresh();
$accepted=new RenewalAcceptance($binding,true,true,25);$features=new WriteFeatures(renewal:true);
$engine=new RenewalExecution($renewBindings,$authority,new RenewalAdmission($authority,$renewBindings,$accepted,$clock),$accepted,$features,$clock);
$accountGuard=(object)['email'=>'expected@example.com','id'=>'900','default'=>'1','mode'=>'singleton','reads'=>0];$writes=0;
$writer=new RenewalWriter($binding,$features,$accepted,fn()=>$engine,static function($origin,$command,$params,$ms)use(&$writes,$accountGuard){
    if($command==='contactList'&&$params===[]){return nameSiloGuardWire($accountGuard);}
    same('renewDomain',$command,'Only the original renewal dispatch');$writes++;
    return json_encode(['request'=>['operation'=>'renewDomain'],'reply'=>['code'=>300,'detail'=>'synthetic','domain'=>'native-history-placeholder.com','order_amount'=>'8.95']]);
});
$server=$holding->service->product->server;
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
Price::where(['server_id'=>$binding->serverId,'action'=>'renew'])->sole()->update(['wholesale'=>'8.95','revision'=>2]);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$bill=$billing->invoice($holding->id,$user);
$renewal=Operation::where('invoice_id',$bill->id)->sole();App\Helpers\ExtensionHelper::addPayment($bill,null,$renewal->amount);Cache::flush();
$clock->instant=new DateTimeImmutable('2027-10-16T12:00:00Z');
same('pending',$engine->run($renewal->id,$writer)->state,'No-ID acknowledged renewal is not completed');$renewal->refresh();$attempt=$renewal->attempt;
$wire->historyId='902';$wire->expiry='2028-10-18';$wire->historyAction='renewal';$wire->historyDate='2027-10-16 05:00:01';
$clock->instant=$clock->now()->modify('+25 hours');
expect(EnomAnswers::eligible($renewal,$clock->now()),'A sealed no-ID NameSilo renewal can receive support evidence');
$staff=commerceUser();$role=App\Models\Role::create(['name'=>'Renewal answers','permissions'=>['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer']]);$staff->update(['role_id'=>$role->id]);
app()->instance(Clock::class,$clock);app()->instance(AccountBindings::class,$renewBindings);
$review=new Review($staff->id,$server->id,null,'enom_answer',Review::ticket($staff->id,$server->id,null,'enom_answer',Review::stamp($server->id,null)),'Record exact synthetic renewal answer');
(new StaffSettingsWrites($native,$sellers,$renewBindings))->run($review,['operation_id'=>$renewal->id,'attempt_hash'=>EnomAnswers::identity($renewal),'outcome'=>'accepted',
    'order_reference'=>'902','processed_at'=>'2027-10-16T05:00:01-07:00','case_reference'=>'synthetic-renewal-case',
    'evidence'=>'Registrar confirmed the exact account, renewal, order and processing time with UTC offset.','confirmed'=>true],$clock->now());
same($attempt,$renewal->fresh()->attempt,'Recording support evidence preserves the original renewal attempt');
same('2027-10-18',$holding->fresh()->provider_expires_at->format('Y-m-d'),'A support answer alone does not extend expiry');
$wire->commands=[];$completion=new RenewalCompletion($renewBindings,$clock);
same('succeeded',$completion->run($renewal->id)->state,'Exact readback completes the supported renewal without history acceptance');
expect(!in_array('listOrders',$wire->commands,true),'Exact order support uses no history enumeration');
same('2028-10-18',$holding->fresh()->provider_expires_at->format('Y-m-d'),'Only read-confirmed expiry advances');
expect(RenewalAuthority::completed($renewal->fresh(),$holding->fresh(),$binding),'Completed support renewal reconstructs its authority');
$engine->run($renewal->id,$writer);same(1,$writes,'Support recovery never dispatches the renewal twice');
echo "Registrar renewal answer requires exact readback and never repeats a sent renewal.\n";$nativeSuccessful=true;
