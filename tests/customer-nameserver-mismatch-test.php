<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/owner-records.php';
require_once __DIR__.'/Support/site-dns-default.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainPage;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationRecords,RegistrationDns};

$desired=['ns1.provider.example','ns2.provider.example'];
$profile=DB::transaction(fn()=>WatchProfile::create(['name'=>'Registration nameservers','enabled'=>true,'nameservers'=>$desired,
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$user->id,'reason'=>'Synthetic registration default']));
siteDnsFixture($profile->id);
[$d,$driver,$original]=$holding(placeholderComFixtureName('confirmed-mismatch-placeholder.com'),NameserverEvidence::requested($desired,'registration_request',$clock->now()));
same($desired,$original->payload['nameservers'],'Checkout freezes the desired nameservers');
same('active',$d->status,'The registration is financially complete');
// A real core-only registrar read confirms fallback DNS before any inventory read finishes.
$observation->nameservers=['dns1.name-services.com','dns2.name-services.com'];
$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock));
same('refreshed',$observer->run($d->id,readRecords:false),'The core registrar read confirms the active domain');
$d=$d->fresh();
expect(RegistrationDns::pending($original->fresh(),$d),'Observed fallback DNS proves the registration mismatch');
same(null,ObservationRecords::current($d,$clock->now()),'No complete or partial inventory has been read');
storefrontViews();
$view=(new OwnedDomainController($owners))->show($post('dns'),$d->id);
$owned=$view->getData()['domain'];
expect($owned->dnsCorrection,'The real customer projection carries the confirmed mismatch');
expect(DomainPage::initialRead($owned),'The controller applies the initial inventory state through RecordFamilies::owner');
$html=$view->render();$dom=storefrontDom($html);
$notice=trim($dom->query('//span[contains(@class,"domains-notice__tx")]')->item(0)->textContent);
expect(str_contains($notice,'The registered nameservers do not match the registration settings.'),'A confirmed mismatch wins over the initial Checking notice');
expect(!str_contains($notice,DomainPage::INITIAL_READ),'The repair notice is not replaced by the inventory notice');
$links=$dom->query('//*[contains(concat(" ",normalize-space(@class)," ")," domains-notice ")]//a[normalize-space(.)="Change nameservers"]');
same(1,$links->length,'The repair notice links to Change nameservers');
same($owned->actions['show'].'?edit=nameservers#edit-nameservers',$links->item(0)->getAttribute('href'),'The repair link opens the nameserver editor');
$rows=$dom->query('//span[@data-record-family]');
same(7,$rows->length,'The customer page renders all seven registrar families');
foreach($rows as $row){same('Checking',trim($row->textContent),'Unconfirmed inventory rows remain calm beside a confirmed mismatch');}
same(0,$dom->query('//*[contains(concat(" ",normalize-space(@class)," ")," domains-missing ")]')->length,'Unconfirmed inventory does not show Missing');
$nativeSuccessful=true;echo "Confirmed nameserver mismatch before the first complete inventory passed.\n";
