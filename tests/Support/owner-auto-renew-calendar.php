<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native owner renewal preference scenarios.
declare(strict_types=1);
require __DIR__.'/owner-auto-renew.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Invoice,ServiceCancellation};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,OwnerAutoRenewController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Models\{Operation,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,OwnerAutoRenew,RenewalStop,RuntimeIneligible,RuntimeReason};
// Scheduled billing keeps its due-date cutoff; manual requests remain available until expiry.
foreach($calendarDays as $day){
    [$calendar]=$holder(placeholderComFixtureName('owner-calendar-'.str_replace('-','',$day).'-placeholder.com'));$clock->instant=new DateTimeImmutable($day.'T12:00:00Z');
    $save($calendar,false);same(null,$billing->invoice($calendar->id),'Off skips at '.$day);$save($calendar,true);
    if($day>='2027-09-27'&&$day<'2027-10-11'){expect($billing->invoice($calendar->id)!==null,'On issues inside window '.$day);}
    else{try{$billing->invoice($calendar->id);expect(false,'Outside calendar refused');}catch(RuntimeIneligible $e){same(RuntimeReason::OutsideCalendar,$e->reason,'Exact calendar refusal '.$day);}}
    if($day>='2027-10-18'){same([],$owners->present($calendar->fresh())->renewNow,'Expired holding has no new manual request');}
    elseif($day>='2027-10-11'){
        same([1],$owners->present($calendar->fresh())->renewNow['terms']??null,'After due annual renewal remains available without an exact longer-term quote');
        same(range(1,9),\Paymenter\Extensions\Servers\Domains\Services\DomainBilling::terms(new DateTimeImmutable($calendar->fresh()->provider_expires_at->format(DATE_ATOM)),$clock->now()),'Registry horizon still permits nine years independently of unproved exact quotes');
    }
}
