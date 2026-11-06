<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native owner renewal preference scenarios.
declare(strict_types=1);
require_once __DIR__.'/Support/renewal-initialized.php';
$fixtureInitialization=initializedRenewalFixture(...);
require_once __DIR__.'/Support/scenario-inventory.php';
$scenarios=new NativeScenarioInventory(['2027-09-26','2027-09-27','2027-10-10','2027-10-11','2027-10-17','2027-10-18','2027-10-19']);
$ownerFixtureNames=['owner-calendar-20270926','owner-calendar-20270927','owner-calendar-20271010','owner-calendar-20271011','owner-calendar-20271017','owner-calendar-20271018','owner-calendar-20271019'];
require __DIR__.'/Support/owner-auto-renew.php';
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
foreach(['2027-09-26','2027-09-27','2027-10-10','2027-10-11','2027-10-17','2027-10-18','2027-10-19'] as $day){
    [$calendar,$calendarBinding]=$holder(placeholderComFixtureName('owner-calendar-'.str_replace('-','',$day).'-placeholder.com'));$clock->instant=new DateTimeImmutable($day.'T12:00:00Z');
    activeCommerceFacts($clock->now(),$clock->now()->modify('+30 days'),activeFixturePorts($bindings));$observeRenewalCost($calendarBinding);
    $save($calendar,false);same(null,$billing->invoice($calendar->id),'Off skips at '.$day);$save($calendar,true);
    if($day>='2027-09-27'&&$day<'2027-10-11'){expect($billing->invoice($calendar->id)!==null,'On issues inside window '.$day);}
    else{try{$billing->invoice($calendar->id);expect(false,'Outside calendar refused');}catch(RuntimeIneligible $e){same(RuntimeReason::OutsideCalendar,$e->reason,'Exact calendar refusal '.$day);}}
    if($day>='2027-10-18'){same([],$owners->present($calendar->fresh())->renewNow,'Expired holding has no new manual request');}
    elseif($day>='2027-10-11'){same([1],$owners->present($calendar->fresh())->renewNow['terms']??null,'After due the same evidenced annual term is offered');}
    $scenarios->completed($day);
}
$scenarios->finish();same([],array_keys($registrations),'Every calendar holding was consumed');
echo "Owner renewal scheduling calendar passed.\n";$nativeSuccessful=true;
