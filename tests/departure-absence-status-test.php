<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The existing Enom transport supplies synthetic exceptions.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,DomainStates,HoldingReadRetry,RenewalOwnership};

$healthy=$d->fresh()->observation;$money=$nativeMoney();$originalBefore=$original->fresh()->getAttributes();
$failures=[];$check=static function(mixed $want,mixed $got,string $why)use(&$failures):void{if($want!==$got){$failures[]=$why;}};
foreach(['grace','expired'] as $status){foreach(['none','owned'] as $prior){
    $case=$status.' with '.$prior.' retained proof';$saved=$healthy;
    if($prior==='none'){unset($saved['renewal_ownership']);}
    $d->refresh()->update(['status'=>$status,'observation'=>$saved]);
    $clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='absent';
    same('domain_not_in_account',$observer->run($d->id,readRecords:true),'The real exception classifier records absence: '.$case);
    $absentAt=$clock->now()->format(DATE_ATOM);
    foreach(['definite absence','unknown read'] as $step){
        if($step==='unknown read'){
            $clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='transport';
            same('read_failed',$observer->run($d->id,readRecords:true),'A transport error replaces only the diagnostic: '.$case);
            $check('read_failed',$d->fresh()->observation['record_failure']['code'],'The new diagnostic is saved: '.$case);
        }
        $fresh=$d->fresh();$label=$case.' after '.$step;
        $check(false,$fresh->observation['renewal_ownership']['owned']??null,'Definite absence is retained: '.$label);
        $check($absentAt,$fresh->observation['renewal_ownership']['at']??null,'Unknown reads keep the absence time: '.$label);
        $check(true,HoldingReadRetry::absent($fresh),'Absence survives without positive ownership: '.$label);
        $check(true,RenewalOwnership::blocked($fresh),'Collection remains fenced: '.$label);
        $check(true,DepartureChecks::suspected($fresh),'Departure stays suspected: '.$label);
        $check('departure_suspected',DomainStates::managed($fresh,null,$clock->now()->format('Y-m-d'),false)[0],'Derived state is retained: '.$label);
        $check(1,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->count(),'The single review alert stays open: '.$label);
    }
    $clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='';
    same('refreshed',$observer->run($d->id,readRecords:true),'Positive same-identity ownership is read: '.$case);
    $fresh=$d->fresh();
    $check(false,HoldingReadRetry::absent($fresh),'Positive ownership clears absence: '.$case);
    $check(false,RenewalOwnership::blocked($fresh),'Positive ownership releases the collection fence: '.$case);
    $check(false,DepartureChecks::suspected($fresh),'Positive ownership clears suspicion: '.$case);
    $check(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->count(),'Positive ownership resolves the alert: '.$case);
    $check($status,$fresh->status,'Read results preserve the holding status: '.$case);
}}
same($money,$nativeMoney(),'Absence retention changes no money');same($originalBefore,$original->fresh()->getAttributes(),'Absence retention preserves the purchase');
expect($failures===[],implode("\n",$failures));
$nativeSuccessful=true;echo "Grace and expired exception-based absence survives unknown reads with no proof or an older positive proof.\n";
