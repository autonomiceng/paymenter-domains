<?php
// Demo C C1 wired observation and Enom CAA hold; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
require_once __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,OwnerRecords};
ObservationSchema::install();$budget=new AccountReadBudget($clock);$observer=new DomainObservation($readBindings,$clock,$budget,$ports);
[$d,$driver,$original]=$holding(placeholderComFixtureName('c1-observation-placeholder.com'));
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$state->contacts=array_fill_keys(['registrant','admin','tech','auxbilling'],['FirstName'=>'Synthetic','LastName'=>'Person','Address1'=>'1 Example Street','City'=>'Portland','PostalCode'=>'97201','Country'=>'US','EmailAddress'=>'synthetic@example.com','Phone'=>'+1.5035550123']);
$caa=['name'=>'@','type'=>'CAA','address'=>'0 issue "ca.example"','mx_pref'=>10];
$hold=static fn()=>[$d->fresh()->observation['record_hold']['reason']??null,$d->fresh()->observation['record_hold']['family']??null];
$tick=static function()use($clock):void{$clock->instant=$clock->now()->modify('+61 seconds');};
same('refreshed',$observer->run($d->id,readRecords:true),'Legacy refresh without a dedicated reader still succeeds');
same(null,$d->fresh()->observation['records']['format']??null,'Legacy refresh keeps the unversioned record row');

// Enom CAA stays held (fail closed) with its own staff reason and next step.
$state->dns[]=$caa;$tick();
same('refreshed',$observer->run($d->id,readRecords:true),'Legacy Enom CAA read holds only records');
same(['caa_unsupported','dns'],$hold(),'Legacy Enom CAA names its reason and family');
expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'CAA keeps the DNS editor off');
Auth::login(staffUser([Staff::VIEW]));
same("Sync stopped: this domain has CAA records, which can't be read from Enom here. Other domain facts were saved. Manage this domain's DNS at Enom.",DomainFacts::recordHold($d->fresh()),'CAA hold gives staff a plain reason and next step');
array_pop($state->dns);$tick();$observer->run($d->id,readRecords:true);
expect(!ObservationRecords::held($d->fresh()),'Removing CAA clears the legacy hold');

// The runtime resolver drives the dedicated reader through DomainObservation::run, with no registrar writes.
$wired=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$exchange));
$writes=static fn()=>array_values(array_filter(array_column($state->calls,0),static fn($c)=>!str_starts_with($c,'Get')&&$c!=='CheckNSStatus'));
$state->calls=[];$state->fault='GetDomainSRVHosts';$tick();
$wired->run($d->id,readRecords:true);
$r=$d->fresh()->observation;same(1,$r['records']['format'],'Wired refresh writes versioned records');
same(array_fill_keys(['dns','srv','ds','glue'],'supported'),$r['records']['expected_families'],'Wired refresh writes all four expectations');
same(['records_unavailable','srv'],$hold(),'Failed family is named on the hold');
$status=ObservationProjection::recordStatus($d->fresh(),$clock->now());same([true,'srv'],[$status['resume'],$status['failed_family']],'Staff status offers resume for the failed family');
$state->fault='';$tick();
$wired->run($d->id,readRecords:true);
expect(!ObservationRecords::held($d->fresh()),'Complete wired refresh clears the hold');
same($clock->now()->format(DATE_ATOM),ObservationProjection::recordStatus($d->fresh(),$clock->now())['last_complete_at'],'Complete wired refresh advances last complete time');
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

$nativeSuccessful=true;echo "C1 observation: legacy fallback, CAA hold copy, wired format=1 cycle, resume, glue reason and one clock passed.\n";
