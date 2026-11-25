<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';require __DIR__.'/Support/WireLimit.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DateEvidence, ExpiryPrecision, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\Runtime;
[$unpaid]=$makeRenewal(placeholderComFixtureName('unfunded-renewal-placeholder.com'),false);
DB::table('domains_operations')->where('id',$unpaid->id)->update(['state'=>'running','request_fingerprint'=>str_repeat('a',64),'lease_expires_at'=>$clock->now()->modify('+360 seconds'),
    'attempt'=>json_encode(['claim_key'=>'forged-claim','seal'=>'sealed','send_state'=>'possibly_sent','renewal_mode'=>'ordinary'])]);
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('capacity-renewal-placeholder.com'));[$engine,$writer]=$compose($b);$before=$state->writes;
// A writer reaches its exchange only through a grant the gate issued for a sealed, funded attempt; a caller's check is never one.
$forge=static function(Operation $target,string $case)use($engine,$writer,$clock,$b,$state):void{$target=$target->fresh();$before=$state->writes;
    $grant=new AdmittedOperation($b,new Query($target->domain->domain,Action::Renew,1),$target->operation_key,'forged-claim',$target->domain->authority_generation,
        'forged-claim',str_repeat('a',64),new Money('8.00','USD'),new DateEvidence($target->base_expires_at->format('Y-m-d'),ExpiryPrecision::Date,'renewal_base'));
    $ctx=new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+60 seconds'),new RequestBudget(1),$clock,Runtime::limiter());
    try{$engine->gate->issue($grant,$ctx,$writer,static fn():string=>'ordinary');}catch(Throwable){}
    try{$writer->renew($grant,$ctx);}catch(DomainException){}
    same($before,$state->writes,'No grant and no exchange for an '.$case.' attempt');
};
$forge($op,'unsealed paid');
$forge($unpaid,'unfunded sealed');
app()->instance(Runtime::class,new Runtime);$limit=new WireLimit(12);app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,$limit);
$result=$engine->run($op->id,$writer);same(13,$limit->calls,'Initial/final read wires admitted before refused renewal');same($before,$state->writes,'Capacity refusal has zero renewal exchanges');same('queued',$result->state,'Capacity refusal defers renewal');expect(empty($result->attempt),'Renewal refusal precedes immutable seal');
$limit->allowed=100;Illuminate\Support\Facades\Cache::flush();$result=$engine->run($op->id,$writer);same('pending',$result->state,'Renewal recovers after capacity returns');same($before+1,$state->writes,'Exactly one renewal exchange after recovery');
echo "Installed physical renewal capacity refusal and recovery passed.\n";$nativeSuccessful=true;
