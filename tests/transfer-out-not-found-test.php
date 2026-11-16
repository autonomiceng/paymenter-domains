<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
use App\Models\{BillingAgreement, Gateway, Invoice};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, DomainBilling, DomainObservation, HoldingLocks, ObservationRecords, ObligationAlerts, RenewalAutoPay, RenewalOwnership};

class MissingHoldingGateway
{
    public static int $calls=0;
    public function __construct(array $settings) {}
    public function charge(Invoice $invoice, $amount, BillingAgreement $agreement): bool
    { same(0,DB::transactionLevel(),'Payment transport stays outside locks'); self::$calls++; return false; }
}
class_alias(MissingHoldingGateway::class,'Paymenter\\Extensions\\Gateways\\MissingHoldingDouble\\MissingHoldingDouble');
$gateway=Gateway::create(['name'=>'Synthetic','extension'=>'MissingHoldingDouble','type'=>'gateway','enabled'=>true]);
$agreement=BillingAgreement::create(['user_id'=>$user->id,'gateway_id'=>$gateway->id,'name'=>'Synthetic','external_reference'=>'synthetic']);
$d->service->update(['billing_agreement_id'=>$agreement->id]);
foreach(['domains_billing_enabled','domains_manual_renewals_enabled'] as $key){$product->server->settings()->updateOrCreate(['key'=>$key],['value'=>true,'type'=>'boolean']);}
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$billing=new DomainBilling($bindings,$guard,$clock,$caps??null); $auto=new RenewalAutoPay($clock);
$read=static function(string $fault='',?DomainObservation $reader=null)use($d,$observer,$observation,$clock):string{
    $observation->fault=$fault;
    $observation->during=$fault==='timeout'?static function()use($clock):void{$clock->instant=$clock->now()->modify('+251 seconds');}:null;
    $clock->instant=$clock->now()->modify('+61 seconds');
    return ($reader??$observer)->run($d->id,readRecords:true);
};
$failures=[];
$check=static function(mixed $expected,mixed $actual,string $why)use(&$failures):void{if($expected!==$actual){$failures[]=$why;}};
$refused=static function(callable $call):bool{try{$call();return false;}catch(DomainException){return true;}};
$reviews=static fn()=>DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->whereIn('code',['holding_absent','departure_review'])->whereNull('resolved_at');
$inspect=static function()use($d,$clock):void{
    $l=DB::transaction(fn()=>HoldingLocks::take($d->fresh()));
    ObligationAlerts::finish($l['domain'],ObligationAlerts::retained($l,$clock->now()->getTimestamp()),$clock->now()->getTimestamp());
};

// The common transfer-away answer is a definite exception, after a successful ownership read.
same('refreshed',$read(),'Records-enabled positive observation succeeds');
same(true,$d->fresh()->observation['renewal_ownership']['owned'],'The holding starts with a saved positive proof');
$money=$nativeMoney(); $calls=MissingHoldingGateway::$calls;
same('domain_not_in_account',$read('absent'),'The existing Enom not-found fixture is classified definitely absent');
$check(false,$d->fresh()->observation['renewal_ownership']['owned']??null,'Not-found replaces the earlier positive ownership proof');
foreach([null,$user] as $actor){$check(true,$refused(fn()=>$billing->invoice($d->id,$actor)),'Not-found refuses '.($actor?'manual':'scheduled').' new debt');}
$check($money,$nativeMoney(),'Not-found creates no debt or payment');
$check($calls,MissingHoldingGateway::$calls,'Not-found schedules no Auto Pay');
$check(1,$reviews()->count(),'Not-found raises one immediate departure review');
// A stopped observer must not also produce a second generic review for the same absence.
$fresh=$d->fresh(); $follow=$fresh->observation['followup']??[];
DB::table('domains_observation_work')->updateOrInsert(['domain_id'=>$d->id,'generation'=>$d->authority_generation,
    'phase'=>isset($follow['operation_key'])?hash('sha256',$follow['operation_key']):'holding'],
    ['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'due_at'=>$clock->now()->getTimestamp(),'safe_code'=>'domain_not_in_account']);
$inspect();
$check(1,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->whereIn('code',['holding_absent','departure_review','observation_review'])->whereNull('resolved_at')->count(),'One absence has one staff review');

// Freeze collection authority before a later not-found response; check again at payment time.
$read(); $invoice=$billing->invoice($d->id); $context=$auto->renewalAutoPayContext($invoice->id);
expect(is_array($context),'Positive ownership permits a current collection context');
$healthy=$d->fresh()->observation;
same(true,DB::transaction(fn()=>$auto->renewalAutoPayStillAllowed($invoice->id,$context)),'Positive ownership permits payment-time confirmation');
$read('absent');
$check(null,$auto->renewalAutoPayContext($invoice->id),'Not-found withdraws collection authority');
$check(false,DB::transaction(fn()=>$auto->renewalAutoPayStillAllowed($invoice->id,$context)),'Not-found refuses a frozen context at payment time');
$read('timeout'); $check(true,RenewalOwnership::blocked($d->fresh()),'Timed-out Sync now cannot clear newly saved absence');
$read(); $check($context,$auto->renewalAutoPayContext($invoice->id),'Same-identity positive ownership re-arms collection');

// Existing observations have no renewal_ownership entry. Preserve their proof before diagnostics change.
foreach(['timeout'=>'read_deadline','transport'=>'read_failed','info_missing'=>'read_no_evidence'] as $fault=>$code){
    $legacy=$healthy; unset($legacy['renewal_ownership']); $legacy['facts']['owned']=false;
    $legacy['record_failure']=['identity'=>ObservationRecords::identity($d->fresh()),'at'=>$clock->now()->format(DATE_ATOM),'code'=>'domain_not_in_account'];
    $legacy['record_hold']=[...$legacy['record_failure'],'reason'=>'domain_not_in_account'];
    $d->fresh()->update(['observation'=>$legacy]);
    same(true,RenewalOwnership::blocked($d->fresh()),'Legacy absence initially fences billing');
    RenewalOwnership::review($d->fresh(),$clock->now()->getTimestamp());
    $money=$nativeMoney(); $original=$d->operations()->get()->map->getAttributes()->all();
    same($code,$read($fault),'Protected Sync now retains the actual failure diagnostic');
    $check(true,RenewalOwnership::blocked($d->fresh()),'Legacy absence survives '.$fault);
    $check(true,$refused(fn()=>DB::transaction(fn()=>DomainBilling::requireAuthority(HoldingLocks::take($d->fresh()),$bindings,true))),'Legacy absence still refuses new debt authority after '.$fault);
    $check(null,$auto->renewalAutoPayContext($invoice->id),'Legacy absence still refuses collection context after '.$fault);
    $check(false,DB::transaction(fn()=>$auto->renewalAutoPayStillAllowed($invoice->id,$context)),'Legacy absence still refuses payment-time confirmation after '.$fault);
    $inspect(); $check(1,$reviews()->count(),'Legacy absence retains one open departure review after '.$fault);
    $check($money,$nativeMoney(),'Failed sync leaves invoice, payments and credit unchanged');
    $check($original,$d->operations()->get()->map->getAttributes()->all(),'Failed sync leaves request evidence unchanged');
    $observation->domainId='457'; $read(); $check(true,RenewalOwnership::blocked($d->fresh()),'A foreign provider identity cannot clear legacy absence');
    $observation->domainId='456'; $read(); $check($context,$auto->renewalAutoPayContext($invoice->id),'Only positive ownership re-arms the legacy holding');
    $check(0,$reviews()->count(),'Positive ownership clears the departure review');
}

// Turning record reading off does not hide a definite account-absence answer.
$gateOff=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports,static fn()=>false);
try{$result=$read('absent',$gateOff);}catch(DomainException|RuntimeException){$result='escaped';}
$check('domain_not_in_account',$result,'Record-gate-off absence reaches the locked evidence writer');
$check(true,RenewalOwnership::blocked($d->fresh()),'Record-gate-off absence fences billing');
$check(1,$reviews()->count(),'Record-gate-off absence raises the same departure review');
same([],$failures,'Not-found and failed-sync regressions: '.implode('; ',$failures));
echo "Definite not-found and legacy failed-sync collection fences passed.\n";
$nativeSuccessful=true;
