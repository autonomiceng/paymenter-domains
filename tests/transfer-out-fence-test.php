<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline registrar and payment fixtures only.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/reconciliation-fixture.php';
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice};
use Illuminate\Support\Facades\{DB, Mail, Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, DomainBilling, DomainObservation, HoldingLocks, HolderPricing, NativeDomainGuard, ObligationAlerts, ObservationRecords, ObservationSchema, ReminderSchema, RenewalAutoPay};

class TransferFenceGateway
{
    public static int $calls=0;
    public function __construct(array $settings) {}
    public function charge(Invoice $invoice, $amount, BillingAgreement $agreement): bool
    { same(0,DB::transactionLevel(),'Payment transport runs outside locks'); self::$calls++; return false; }
}
class_alias(TransferFenceGateway::class,'Paymenter\\Extensions\\Gateways\\TransferFenceDouble\\TransferFenceDouble');
Queue::fake(); Mail::fake(); ObservationSchema::install(); ReminderSchema::install();
\Paymenter\Extensions\Servers\Domains\Services\PslReviewSchema::install();
app()->instance(AccountBindings::class,$bindings);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock); $guard->boot();
$billing=new DomainBilling($bindings,$guard,$clock,$caps??null); $auto=new RenewalAutoPay($clock);
$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock));
$gateway=Gateway::create(['name'=>'Synthetic','extension'=>'TransferFenceDouble','type'=>'gateway','enabled'=>true]);
$agreement=BillingAgreement::create(['user_id'=>$user->id,'gateway_id'=>$gateway->id,'name'=>'Synthetic','external_reference'=>'synthetic']);
foreach(['domains_billing_enabled','domains_manual_renewals_enabled'] as $key){$product->server->settings()->updateOrCreate(['key'=>$key],['value'=>true,'type'=>'boolean']);}
$holder=static function(string $suffix)use($prepare,$reconcile,$agreement,$clock):Domain{
    [$op]=$prepare(placeholderComFixtureName('transfer-fence-'.$suffix.'-placeholder.com'));
    same('succeeded',$reconcile->run($op->id)->state,'Real paid acquisition supplies the holding anchor');
    $d=$op->domain()->firstOrFail(); $d->service->update(['billing_agreement_id'=>$agreement->id]);
    $clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z'); return $d;
};
$read=static function(Domain $d,string $fault='')use($observer,$observation,$clock):void{
    $observation->name=$d->domain; $observation->fault=$fault;
    $observation->during=$fault==='timeout'?static function()use($clock):void{$clock->instant=$clock->now()->modify('+251 seconds');}:null;
    $clock->instant=$clock->now()->modify('+61 seconds'); $observer->run($d->id,readRecords:false);
};
$failures=[];
$check=static function(mixed $expected,mixed $actual,string $why)use(&$failures):void{if($expected!==$actual){$failures[]=$why;}};
$refused=static function(callable $call):bool{try{$call();return false;}catch(DomainException){return true;}};
$alerts=static fn(Domain $d)=>DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','holding_absent')->whereNull('resolved_at');

// The adapter's explicit foreign account answer proves owned=false, without any live provider call.
$gone=$holder('gone'); $read($gone,'owner');
same(false,$gone->fresh()->observation['facts']['owned'],'Definite not-in-account read saved');
$money=$nativeMoney(); $count=TransferFenceGateway::$calls;
foreach([null,$user] as $actor){$check(true,$refused(fn()=>$billing->invoice($gone->id,$actor)),'Proved absence refuses '.($actor?'manual':'scheduled').' new debt');}
$check($money,$nativeMoney(),'Absent holding creates no invoice, credit settlement or payment');
$check($count,TransferFenceGateway::$calls,'Absent holding schedules no gateway call');
$check(1,$alerts($gone)->count(),'Absence immediately alerts staff');
$read($gone,'owner'); $check(1,$alerts($gone)->count(),'Repeated absence deduplicates staff review');
// A complete sweep must retain this alert even without observation work rows.
$locked=DB::transaction(fn()=>HoldingLocks::take($gone));
$keys=ObligationAlerts::retained($locked,$clock->now()->getTimestamp());
ObligationAlerts::finish($gone->fresh(),$keys,$clock->now()->getTimestamp());
$check(1,$alerts($gone)->count(),'Successful reminder inspection retains current absence');
// A later timeout or unclear read cannot clear an established negative proof.
foreach(['timeout','transport','info_missing'] as $fault){
    try{$read($gone,$fault);}catch(DomainException){}
    $check(true,$refused(fn()=>$billing->invoice($gone->id,$user)),'Unclear read cannot lift established absence: '.$fault);
}
$read($gone); $rearmed=$billing->invoice($gone->id);
$check(true,$rearmed instanceof Invoice,'Later positive read re-arms renewal billing');
$check(0,$alerts($gone)->count(),'Positive read resolves absence review');

// Failure before any negative proof is not absence; billing and payment-time authority stay usable.
$timeout=$holder('timeout');
try{$read($timeout,'timeout');}catch(DomainException){}
$invoice=$billing->invoice($timeout->id); $context=$auto->renewalAutoPayContext($invoice->id);
expect(is_array($context),'Transport failure permits the ordinary Auto Pay context');
same(true,DB::transaction(fn()=>$auto->renewalAutoPayStillAllowed($invoice->id,$context)),'Transport failure does not refuse collection');
$read($timeout,'owner');
$check(null,$auto->renewalAutoPayContext($invoice->id),'Absence withdraws an existing invoice collection context');
$check(false,DB::transaction(fn()=>$auto->renewalAutoPayStillAllowed($invoice->id,$context)),'Payment-time recheck refuses a previously frozen context');
$clock->instant=$clock->now()->modify('-61 seconds'); $read($timeout); $check($context,$auto->renewalAutoPayContext($invoice->id),'Positive read restores the original unpaid obligation authority');

// Model the invoice writer just before issued(): a valid debt exists, but Auto Pay has not been scheduled.
$schedule=$holder('schedule'); $issued=$billing->invoice($schedule->id,$user);
$op=Operation::where('invoice_id',$issued->id)->sole();
$funding=$op->funding; unset($funding['auto_pay']);
DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>encrypt(json_encode($funding),false)]);
$read($schedule,'owner'); $count=TransferFenceGateway::$calls;
DB::transaction(function()use($schedule,$op,$issued,$auto):void{
    $l=HoldingLocks::take($schedule); $auto->issued($op->fresh(),$issued->fresh(),$l,true);
});
$check('skipped',$op->fresh()->funding['auto_pay']['status'],'Absence prevents scheduling Auto Pay on an already created debt');
$check($count,TransferFenceGateway::$calls,'Schedule refusal makes no payment call');

// Keep paid invoice, item, payment and original operation bytes; absence never cancels or refunds them.
$paid=$holder('paid'); $paidInvoice=$billing->invoice($paid->id,$user);
ExtensionHelper::addPayment($paidInvoice,null,Operation::where('invoice_id',$paidInvoice->id)->sole()->amount);
$money=$nativeMoney(); $operations=$paid->operations()->get()->map->getAttributes()->all(); $reservation=$paid->reservation_name;
$read($paid,'owner'); $check($paidInvoice->id,$billing->invoice($paid->id,$user)->id,'Existing paid invoice remains retrievable');
$check($money,$nativeMoney(),'Absence leaves all existing paid debt and credit bytes untouched');
$check($operations,$paid->operations()->get()->map->getAttributes()->all(),'Absence leaves paid operation evidence untouched');
$check($reservation,$paid->fresh()->reservation_name,'Absence never releases the name');

// Retained proof from a different generation or identity cannot fence this holding.
$evidence=$schedule->fresh()->observation;
$evidence['record_failure']['identity']['generation']++;
$evidence['facts']['generation']++;
if(isset($evidence['renewal_ownership'])){$evidence['renewal_ownership']['identity']['generation']++;}
$schedule->fresh()->update(['observation'=>$evidence]);
DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>encrypt(json_encode($funding),false)]);
DB::transaction(function()use($schedule,$op,$issued,$auto):void{
    $l=HoldingLocks::take($schedule->fresh()); $auto->issued($op->fresh(),$issued->fresh(),$l,true);
});
$check('uncertain',$op->fresh()->funding['auto_pay']['status'],'A different generation negative proof does not block scheduling');

// A pending acquisition is not expected in the account and keeps its original payment and request state.
[$pending]=$prepare(placeholderComFixtureName('transfer-fence-pending-placeholder.com')); $pendingDomain=$pending->domain()->firstOrFail();
$before=$pending->getAttributes(); $money=$nativeMoney(); $read($pendingDomain,'owner');
$check($before,$pending->fresh()->getAttributes(),'Pending acquisition remains unchanged by not-in-account read');
$check($money,$nativeMoney(),'Pending acquisition money remains unchanged');
$check(0,$alerts($pendingDomain)->count(),'Pending acquisition does not raise a departure review');

same([],$failures,'Transfer-out fence regressions: '.implode('; ',$failures));
echo "Transfer-out billing, Auto Pay, recovery, identity and retained-money fences passed.\n";
$nativeSuccessful=true;
