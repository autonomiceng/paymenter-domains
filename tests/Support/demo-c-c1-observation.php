<?php
// Demo C C1 wired observation and Enom CAA hold; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/management.php';
require_once __DIR__.'/staff-panel.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,OwnerRecords};
$ports=new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts(function($b)use(&$driver){return $driver;});
ObservationSchema::install();$budget=new AccountReadBudget($clock);$observer=new DomainObservation($readBindings,$clock,$budget,$ports);
$state->ns=['dns1.name-services.com','dns2.name-services.com'];
[$d,$driver,$original]=$holding(placeholderComFixtureName('c1-observation-placeholder.com'),\Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence::observed($state->ns,'enom_getdns',$clock->now()));
// These checks drive every read by hand. Activation queues a first scheduled read, and while it waits a record hold is not stopped.
Illuminate\Support\Facades\DB::table('domains_observation_work')->where('domain_id',$d->id)->delete();
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$state->contacts=array_fill_keys(['registrant','admin','tech','auxbilling'],['FirstName'=>'Synthetic','LastName'=>'Person','Address1'=>'1 Example Street','City'=>'Portland','PostalCode'=>'97201','Country'=>'US','EmailAddress'=>'synthetic@example.com','Phone'=>'+1.5035550123']);
$caa=['name'=>'@','type'=>'CAA','address'=>'0 issue "ca.example"','mx_pref'=>10];
$hold=static fn()=>[$d->fresh()->observation['record_hold']['reason']??null,$d->fresh()->observation['record_hold']['family']??null];
$tick=static function()use($clock):void{$clock->instant=$clock->now()->modify('+61 seconds');};
same('refreshed',$observer->run($d->id,readRecords:true),'Legacy refresh without a dedicated reader still succeeds');
same(null,$d->fresh()->observation['records']['format']??null,'Legacy refresh keeps the unversioned record row');
