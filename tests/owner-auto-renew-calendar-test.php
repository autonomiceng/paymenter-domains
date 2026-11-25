<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Complete native owner calendar matrix.
declare(strict_types=1);
require __DIR__.'/Support/owner-auto-renew.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Paymenter\Extensions\Servers\Domains\Services\{RuntimeIneligible,RuntimeReason};
// Scheduled billing keeps its due-date cutoff; manual requests remain available until expiry.
// One case per calendar day, each on its own holding of the same owner.
$calendarDay=static function(string $day)use($holder,$clock,$save,$billing,$owners):void {
    [$calendar]=$holder(placeholderComFixtureName('owner-calendar-'.str_replace('-','',$day).'-placeholder.com'));$clock->instant=new DateTimeImmutable($day.'T12:00:00Z');
    $save($calendar,false);same(null,$billing->invoice($calendar->id),'Off skips at '.$day);$save($calendar,true);
    if($day>='2027-09-27'&&$day<'2027-10-11'){expect($billing->invoice($calendar->id)!==null,'On issues inside window '.$day);}
    else{try{$billing->invoice($calendar->id);expect(false,'Outside calendar refused');}catch(RuntimeIneligible $e){same(RuntimeReason::OutsideCalendar,$e->reason,'Exact calendar refusal '.$day);}}
    if($day>='2027-10-18'){same([],$owners->present($calendar->fresh())->renewNow,'Expired holding has no new manual request');}
    elseif($day>='2027-10-11'){
        same([1],$owners->present($calendar->fresh())->renewNow['terms']??null,'After due annual renewal remains available without an exact longer-term quote');
        same(range(1,9),\Paymenter\Extensions\Servers\Domains\Services\DomainBilling::terms(new DateTimeImmutable($calendar->fresh()->provider_expires_at->format(DATE_ATOM)),$clock->now()),'Registry horizon still permits nine years independently of unproved exact quotes');
    }
};
// The first three days cover the scheduled invoice window; the last four the manual due-date and expiry cutoffs.
// Pin each case to the prepared read and Carbon clocks explicitly, whatever a reset restores.
$pinned=[$clock->now(),Carbon\Carbon::getTestNow(),Carbon\CarbonImmutable::getTestNow()];
$pin=static function()use($clock,$pinned):void{[$clock->instant,$carbon,$immutable]=$pinned;Carbon\Carbon::setTestNow($carbon);Carbon\CarbonImmutable::setTestNow($immutable);};
$cases=[];
foreach(['2027-09-26','2027-09-27','2027-10-10','2027-10-11','2027-10-17','2027-10-18','2027-10-19'] as $day){
    $cases[$day]=static function()use($pin,$calendarDay,$day):void{$pin();$calendarDay($day);};
}
nativeFixtureCases($cases,compact('clock','state','observation','preflight','user','other','product','billing','session'));
echo "Owner renewal calendar and manual cutoffs passed.\n";$nativeSuccessful=true;
