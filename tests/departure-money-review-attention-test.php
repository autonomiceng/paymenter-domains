<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/departure-credit.php';
require_once __DIR__.'/Support/fixture-checkpoint.php';
require_once __DIR__.'/Support/renewal-attempts.php';
use Illuminate\Support\Facades\{Cache,Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainQueries;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;
use Paymenter\Extensions\Servers\Domains\Holding\Observation\ObservationRecords;

$nativeSuccessful=false;
$restoreCredit = nativeFixtureCheckpoint();
$ids=static fn()=>DomainQueries::alerting(Domain::query())->pluck('id')->map(fn($id)=>(int)$id)->all();
// The registrar charged more than the authorized amount, and above the customer price, so the saved reason is
// below_cost. Staff never reviewed it. The departure ends registrar work, not that money review.
$acquisition=$d->operations()->whereIn('action',['register','transfer'])->where('state','succeeded')->orderBy('id')->firstOrFail();
expect(($acquisition->policy['reconciliation']['reason']??null)==='below_cost'&&($acquisition->policy['reconciliation']['staff_alert']??false),'The fixture registration keeps its below-cost review');
// Retained cost evidence is frozen on the model; model a record whose actual charge exceeds its authorized charge.
$charged=static function(string $amount)use($acquisition):void{
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$acquisition->id)->update(['cost'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode([...$acquisition->fresh()->cost,'actual'=>['amount'=>$amount,'currency'=>'USD']]))]);
};
$authorized=(string)($acquisition->attempt['authorized_cost']['amount']??'');expect($authorized!=='','The fixture registration keeps its authorized charge');
$charged(number_format((float)$authorized+21,2,'.',''));
ObligationAlerts::record($d->fresh(),'operation_review',$clock->now()->getTimestamp(),(int)$acquisition->id);
Cache::flush();
expect(in_array((int)$d->id,$ids(),true),'An unreviewed charge on a departed domain stays in Needs attention');
Cache::flush();
expect(DomainQueries::openAlertCount(Domain::whereKey($d->id))===1,'An unreviewed charge on a departed domain stays in the staff count');
// Money still moving on the departed domain names the step; the retained charge review keeps it in Needs attention.
ObligationAlerts::record($d->fresh(),'funds_wait_review',$clock->now()->getTimestamp(),(int)$renewal->id);
$status=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($d->fresh());
same([true,'funds_wait_review',(int)$renewal->id],[$status['act'],$status['alert'],$status['request']],'A live money alert names the step ahead of the retained charge review');
Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','funds_wait_review')->update(['resolved_at'=>$clock->now()->getTimestamp()]);
$status=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($d->fresh());
same([true,'operation_review',(int)$acquisition->id],[$status['act'],$status['alert'],$status['request']],'The retained charge review names the step on its own');
// An earlier stopped registrar read adds nothing: it is obsolete after the departure.
ObservationRecords::failPrimary($d->fresh(),$clock->now());
Cache::flush();
expect(DomainQueries::openAlertCount(Domain::whereKey($d->id))===1,'An obsolete stopped read does not add to the staff count');
// A pure below-cost review (charged exactly the authorized amount, still above the customer price) closes at
// departure: the loss is booked and no renewal is left to reprice.
$charged($authorized);
Cache::flush();
expect(!in_array((int)$d->id,$ids(),true),'A pure below-cost review leaves the departed domain settled');

$restoreCredit();
// The fixture's registration review is a pure below-cost one, which closes at departure.
Cache::flush();
expect(!in_array((int)$d->id,$ids(),true),'The departed fixture starts settled');
// Retained renewal evidence is frozen on the model; model a finished renewal whose registrar charge was saved
// under renewal_execution, the only place RenewalCompletion keeps its review. Its seal holds the authorized charge.
$authorized=number_format((float)$renewal->amount+5,2,'.','');
$base=sealRenewal($renewal->fresh(),amount:$authorized);
$renewed=static function(string $reason,string $amount)use($renewal,$base,$authorized):void{
    $json=static fn(array $value)=>Crypt::encryptString(json_encode($value));
    DB::table('domains_operations')->where('id',$renewal->id)->update(['state'=>'succeeded','safe_code'=>null,
        'policy'=>$json([...$base->policy,'renewal_execution'=>['staff_alert'=>true,'reason'=>$reason,'refundable_later'=>false]]),
        'cost'=>$json([...($base->cost??[]),'actual'=>['amount'=>$amount,'currency'=>'USD']])]);
};
// The registrar charged more than the authorized cap. Staff never reviewed it; the departure does not settle it.
$renewed('actual_cost_overrun',number_format((float)$authorized+21,2,'.',''));
ObligationAlerts::record($d->fresh(),'operation_review',$clock->now()->getTimestamp(),(int)$renewal->id);
Cache::flush();
expect(in_array((int)$d->id,$ids(),true),'An unreviewed renewal overcharge on a departed domain stays in Needs attention');
Cache::flush();
expect(DomainQueries::openAlertCount(Domain::whereKey($d->id))===1,'An unreviewed renewal overcharge on a departed domain stays in the staff count');
$status=DomainFacts::status($d->fresh());
same([true,'operation_review',(int)$renewal->id],[$status['act'],$status['alert'],$status['request']],'The renewal overcharge review names the step');
// A pure below-cost renewal (charged the authorized amount, still above the customer price) closes at departure.
$renewed('below_cost',$authorized);
Cache::flush();
expect(!in_array((int)$d->id,$ids(),true),'A pure below-cost renewal review leaves the departed domain settled');
// A renewal charged less than its sealed authorization is booked margin, and the departure leaves nothing to reprice.
$renewed('actual_charge_changed',number_format((float)$authorized-1,2,'.',''));
Cache::flush();
expect(!in_array((int)$d->id,$ids(),true),'A renewal charged below its authorization leaves the departed domain settled');

$restoreCredit();
// The synthetic registration was charged a different amount. Staff reviewed that charge here, so the departure leaves
// nothing to do; an unreviewed charge stays in Needs attention (departure-money-review-attention-test).
Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','operation_review')->whereNull('resolved_at')->update(['resolved_at'=>$clock->now()->getTimestamp()]);
// A records read failed before staff confirmed the departure (Nov 16 walk): the record hold stays on the departed domain.
ObservationRecords::failPrimary($d->fresh(),$clock->now());
expect(ObservationRecords::held($d->fresh()),'The departed domain keeps the earlier record hold');
$attention=DomainQueries::alerting(Domain::query())->pluck('id')->map(fn($id)=>(int)$id)->all();
expect(!in_array((int)$d->id,$attention,true),'A confirmed departure leaves Needs attention');
Cache::flush();
expect(DomainQueries::openAlertCount(Domain::whereKey($d->id))===0,'A confirmed departure is not counted as needing staff action');
$found=DomainQueries::status(Domain::query(),['transferred_away'])->pluck('id')->map(fn($id)=>(int)$id)->all();
expect(in_array((int)$d->id,$found,true),'The departed domain stays findable under Transferred away');
$nativeSuccessful=true;
