<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/departure-credit-test.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts,ObservationRecords};

$nativeSuccessful=false;
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
echo "A departed domain keeps a charge above its authorized amount in Needs attention, whatever the saved reason; a pure below-cost review and the obsolete read do not count.\n";
$nativeSuccessful=true;
