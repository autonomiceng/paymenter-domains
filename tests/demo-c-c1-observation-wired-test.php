<?php
// Complete native C4 observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/demo-c-c1-observation.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,OwnerRecords};
Auth::login(staffUser([Staff::VIEW]));
// The runtime resolver drives the dedicated reader through DomainObservation::run, with no registrar writes.
$wired=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$exchange));
$writes=static fn()=>array_values(array_filter(array_column($state->calls,0),static fn($c)=>!str_starts_with($c,'Get')&&$c!=='CheckNSStatus'));
$state->calls=[];$state->fault='GetDomainSRVHosts';$tick();
$wired->run($d->id,readRecords:true);
$r=$d->fresh()->observation;same(1,$r['records']['format'],'Wired refresh writes versioned records');
same(array_fill_keys(['dns','srv','ds','glue'],'supported'),$r['records']['expected_families'],'Wired refresh writes all four expectations');
same(['records_unavailable','srv'],$hold(),'Failed family is named on the hold');
$status=ObservationProjection::recordStatus($d->fresh(),$clock->now());same([true,'srv'],[$status['resume'],$status['failed_family']],'Staff status offers resume for the failed family');
same("Sync stopped: couldn't read SRV records. Other domain facts were saved. Use Sync now to try again.",DomainFacts::recordHold($d->fresh()),'Staff copy names the failed record family');
// A failed Enom contacts read names contacts, not DNS records.
$state->fault='GetContacts';$tick();$wired->run($d->id,readRecords:true);
same(['records_unavailable','contacts'],$hold(),'Failed contacts read is named on the hold');
same("Sync stopped: couldn't read contacts. Other domain facts were saved. Use Sync now to try again.",DomainFacts::recordHold($d->fresh()),'Staff copy names the failed contacts read');
$state->fault='';$tick();
$wired->run($d->id,readRecords:true);
expect(!ObservationRecords::held($d->fresh()),'Complete wired refresh clears the hold');
same($clock->now()->format(DATE_ATOM),ObservationProjection::recordStatus($d->fresh(),$clock->now())['last_complete_at'],'Complete wired refresh advances last complete time');
same([],$writes(),'Wired family failures and resume send no registrar writes');
$nativeSuccessful=true;echo "C1 versioned family failure, contacts and complete resume passed.\n";
