<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/departure-credit-test.php';
use Illuminate\Support\Facades\{Cache,Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,DomainQueries};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;

$nativeSuccessful=false;
$ids=static fn()=>DomainQueries::alerting(Domain::query())->pluck('id')->map(fn($id)=>(int)$id)->all();
// The fixture's registration review is a pure below-cost one, which closes at departure.
Cache::flush();
expect(!in_array((int)$d->id,$ids(),true),'The departed fixture starts settled');
// Retained renewal evidence is frozen on the model; model a finished renewal whose registrar charge was saved
// under renewal_execution, the only place RenewalCompletion keeps its review.
$base=$renewal->fresh();$authorized=number_format((float)$base->amount+5,2,'.','');
$renewed=static function(string $reason,string $amount)use($renewal,$base,$authorized):void{
    $json=static fn(array $value)=>Crypt::encryptString(json_encode($value));
    DB::table('domains_operations')->where('id',$renewal->id)->update(['state'=>'succeeded','safe_code'=>null,
        'policy'=>$json([...$base->policy,'renewal_execution'=>['staff_alert'=>true,'reason'=>$reason,'refundable_later'=>false]]),
        'attempt'=>$json([...$base->attempt,'authorized_cost'=>['amount'=>$authorized,'currency'=>'USD']]),
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
echo "A departed domain keeps a renewal charged above its authorized amount in Needs attention; a pure below-cost renewal review closes.\n";
$nativeSuccessful=true;
