<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema};
use Paymenter\Extensions\Servers\Domains\Presentation\DomainPage;
ObservationSchema::install();
[$d,$driver,$original]=$holding(placeholderComFixtureName('activation-records-placeholder.com'));
same('active',$d->status,'Financial registration completion activates the domain');
$job=HoldingObservationWork::current($d)->first();
expect($job!==null,'Activation immediately queues the first complete registrar read');
same($clock->now()->getTimestamp(),(int)$job->due_at,'The activation read is due now, without waiting for discovery');
$workId=$job->id;
$reconcile->run($original->id);
same([$workId],HoldingObservationWork::current($d->fresh())->pluck('id')->all(),'Repeated completion cannot duplicate or postpone the read');
// A core-only read can have a flag before the registrar settings family is complete.
$p=$d->observation;$p['facts']=['name'=>$d->domain,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint,'locked'=>false];$d->update(['observation'=>$p]);
$before=$original->fresh()->getAttributes();$money=$nativeMoney();
storefrontViews();
$html=$page();$dom=storefrontDom($html);
same(DomainPage::INITIAL_READ,trim($dom->query('//span[contains(@class,"domains-notice__tx")]')->item(0)->textContent),'Active domain stays in the calm checking state until its complete read');
same('Checking',trim($dom->query('//li[@id="lock"]/span[contains(@class,"domains-row__v")]')->item(0)->textContent),'Unconfirmed lock does not claim Off');
same(0,$dom->query('//li[@id="lock"]//button[normalize-space(.)="Turn on"]')->length,'Unconfirmed lock does not ask the customer to turn it on');
same(0,$dom->query('//*[contains(concat(" ",normalize-space(@class)," ")," domains-missing ")]')->length,'Active domain does not show Missing before its first complete read');
// Fill the existing synthetic Enom wire with the contact snapshot the registration sent.
$map=['FirstName'=>'first_name','LastName'=>'last_name','Address1'=>'address1','City'=>'city','StateProvince'=>'state_province','PostalCode'=>'postal_code','Country'=>'country','EmailAddress'=>'email_address','Phone'=>'phone'];
foreach(['registrant','admin','tech','auxbilling'] as $role){foreach($map as $field=>$key){$state->contacts[$role][$field]=$original->payload['contacts'][$role][$key]??'';}}
$observation->nameservers=$state->ns;
$reader=new Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader($driver->capabilities()->binding,$exchange);
$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports,observationReader:static fn()=>$reader);
$worker=new HoldingObservationWork($observer,$clock);
DB::table('domains_observation_queues')->insertOrIgnore(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->where('server_id',$d->server_id)->sole();
same('refreshed',$worker->turn($queue,static fn()=>null),'The ordinary automatic worker completes the first registrar read');
$fresh=$d->fresh();$records=ObservationRecords::current($fresh,$clock->now());
expect($records['last_complete_at']!==null,'The first complete read is recorded');
foreach(['contacts','nameservers','flags'] as $kind){same('complete',$records['settings'][$kind]['state'],'The automatic read resolves '.$kind);}
$html=$page();$dom=storefrontDom($html);
expect(!str_contains($html,DomainPage::INITIAL_READ),'Complete registrar data clears the checking notice');
same('On',trim($dom->query('//li[@id="lock"]/span[contains(@class,"domains-row__v")]')->item(0)->textContent),'The confirmed lock replaces the provisional value');
same(0,$dom->query('//*[contains(concat(" ",normalize-space(@class)," ")," domains-missing ")]')->length,'The first complete read resolves the Active management page');
same($before,$original->fresh()->getAttributes(),'The read preserves registration evidence');same($money,$nativeMoney(),'The read preserves native money');
$nativeSuccessful=true;echo "Active domain automatic first complete read passed.\n";
