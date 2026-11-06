<?php
// Native encrypted refresh regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real holdings and encrypted model casts are required; no owner route/view fixture is needed.
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/management.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationRecords,ObservationSchema,OwnerPorts};
ObservationSchema::install();$budget=new AccountReadBudget($clock);
$ports=new OwnerPorts(function($b)use(&$driver){return $driver;});
$state->ns=['dns1.name-services.com','dns2.name-services.com'];
[$d,$driver,$original]=$holding(placeholderComFixtureName('c1-regressions-placeholder.com'),NameserverEvidence::observed($state->ns,'enom_getdns',$clock->now()));
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$state->contacts=array_fill_keys(['registrant','admin','tech','auxbilling'],['FirstName'=>'Synthetic','LastName'=>'Person','Address1'=>'1 Example Street','City'=>'Portland','PostalCode'=>'97201','Country'=>'US','EmailAddress'=>'synthetic@example.com','Phone'=>'+1.5035550123']);
$wired=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$exchange));
$hold=static fn()=>[$d->fresh()->observation['record_hold']['reason']??null,$d->fresh()->observation['record_hold']['family']??null];
$tick=static function()use($clock):void{$clock->instant=$clock->now()->modify('+61 seconds');};
$writes=static fn()=>array_values(array_filter(array_column($state->calls,0),static fn($c)=>!str_starts_with($c,'Get')&&$c!=='CheckNSStatus'));
$state->calls=[];
// Complete-to-partial refresh retains diagnostic history under a lagging application clock.
$appClock=app()->bound(Clock::class)?app(Clock::class):$clock;
app()->instance(Clock::class,new class($clock) implements Clock{public function __construct(private Clock $c){} public function now():DateTimeImmutable{return $this->c->now()->modify('-1 hour');}});
$tick();$wired->run($d->id,readRecords:true);
$lastComplete=$d->fresh()->observation['records'];
$state->fault='GetDomainSRVHosts';$tick();$wired->run($d->id,readRecords:true);
$partial=$d->fresh()->observation['records'];
same($lastComplete['families']['srv']['last_good'],$partial['families']['srv']['last_good'],'Lagging app clock retains last-good SRV evidence after partial refresh');
same($lastComplete['last_complete_at'],$partial['last_complete_at'],'Lagging app clock retains the last complete refresh time');
expect(ObservationRecords::held($d->fresh()),'Partial refresh remains held with retained diagnostic evidence');
$state->fault='';$tick();$wired->run($d->id,readRecords:true);
app()->instance(Clock::class,$appClock);
expect(!ObservationRecords::held($d->fresh()),'A lagging app clock does not hold a fresh wired refresh');
// A required registrant cannot be omitted even when the other roles are not collected.
$state->contacts['registrant']=['registrantcontactmatch'=>'NotCollected'];
foreach (['admin','tech','auxbilling'] as $role) { $state->contacts[$role]=[$role.'contactmatch'=>'NotCollected']; }
$tick();$wired->run($d->id,readRecords:true);
same('partial',$d->fresh()->observation['records']['settings']['contacts']['state'],'Uncollected registrant keeps contact evidence partial');
same(['records_unavailable','contacts'],$hold(),'Uncollected registrant cannot clear the records hold');
$state->contacts['registrant']=['FirstName'=>'Synthetic','LastName'=>'Person','Address1'=>'1 Example Street','City'=>'Portland','PostalCode'=>'97201','Country'=>'US','EmailAddress'=>'synthetic@example.com','Phone'=>'+1.5035550123'];
$tick();$wired->run($d->id,readRecords:true);
expect(!ObservationRecords::held($d->fresh()),'Uncollected optional roles still allow a complete registrant refresh');
// A real refresh shares its 25 requests between primary, record and settings reads.
$hosts=[];for ($i=1;$i<=13;$i++) { $hosts['ns'.$i.'.'.$d->domain]='8.8.4.4'; }
$state->glue=$hosts;$saved=$d->fresh()->observation;
$saved['records']['families']['glue']['candidates']=array_keys($hosts);$d->update(['observation'=>$saved]);
$turns=[];
for ($turn=0;$turn<2;$turn++) {
    $beforeCalls=count($state->calls);$tick();$wired->run($d->id,readRecords:true);
    same('complete',$d->fresh()->observation['records']['settings']['flags']['state'],'Glue discovery reserves capacity for settings');
    expect(!ObservationRecords::held($d->fresh()),'Successful bounded glue and settings reads clear the hold');
    $turns[]=array_column(array_filter(array_slice($state->calls,$beforeCalls),static fn($call)=>$call[0]==='CheckNSStatus'),1);
}
$checked=static fn($calls)=>array_column($calls,'CheckNSName');
expect(count($turns[0])>0&&count($turns[0])<13,'A refresh bounds glue below the remaining settings budget');
same(13,count(array_unique([...$checked($turns[0]),...$checked($turns[1])])),'Later refresh rotates through every retained glue candidate');
same([],$writes(),'Wired observation cycle and resume send no registrar writes');

$nativeSuccessful=true;echo "C1 refresh regressions: clock retention, required registrant and bounded glue rotation passed.\n";
