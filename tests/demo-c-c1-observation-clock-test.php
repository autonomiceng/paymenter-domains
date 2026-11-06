<?php
// Complete native C4 observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/demo-c-c1-observation.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,OwnerRecords};
// The runtime resolver drives the dedicated reader through DomainObservation::run, with no registrar writes.
$wired=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$exchange));
$writes=static fn()=>array_values(array_filter(array_column($state->calls,0),static fn($c)=>!str_starts_with($c,'Get')&&$c!=='CheckNSStatus'));
$state->calls=[];
$state->dns[]=$caa;$tick();
$wired->run($d->id,readRecords:true);
same(['caa_unsupported','dns'],$hold(),'Wired Enom CAA keeps its own reason');
array_pop($state->dns);
// A wired Enom glue-only failure keeps its own reason.
$observation->nameservers=$state->ns=['ns1.'.$d->domain,'ns2.example.net'];$state->glue['ns1.'.$d->domain]='8.8.4.4';
$state->fault='CheckNSStatus';$tick();$wired->run($d->id,readRecords:true);
same(['glue_unavailable','glue'],$hold(),'Wired Enom glue-only failure names glue');
$state->fault='';
// Save and read-back share the observer's clock, even when the app clock lags behind it.
$appClock=app()->bound(Clock::class)?app(Clock::class):$clock;
app()->instance(Clock::class,new class($clock) implements Clock{public function __construct(private Clock $c){} public function now():DateTimeImmutable{return $this->c->now()->modify('-1 hour');}});
$tick();$wired->run($d->id,readRecords:true);
app()->instance(Clock::class,$appClock);
expect(!ObservationRecords::held($d->fresh()),'A lagging app clock does not hold a fresh wired refresh');
same([],$writes(),'Wired observation cycle and resume send no registrar writes');

$nativeSuccessful=true;echo "C1 wired CAA, glue-only failure and one-clock recovery passed.\n";
