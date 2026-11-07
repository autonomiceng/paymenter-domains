<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{RecordFamilies,OwnedDomain,DomainPage};
use Paymenter\Extensions\Servers\Domains\Services\{ObservationProjection,ObservationRecords,RegistrationDns};
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
Paymenter\Extensions\Servers\Domains\Services\FoundationSchema::install();
storefrontViews();
$now=new DateTimeImmutable('2026-11-07T12:00:00+00:00');
$d=new Domain(['id'=>73,'domain'=>'new-example.com','status'=>'active','registrar_key'=>'enom','server_id'=>1,'authority_generation'=>1,'account_fingerprint'=>str_repeat('a',64),'observation'=>[]]);
$page=new OwnedDomain(name:$d->domain,status:'Active',tone:'ok',expiry:'2027-11-07',renewal:'On',renewalPrice:'$10.99',actions:['show'=>'/domains/73'],progress:[],setup:[],transfer:[],management:['lock'=>['label'=>'Transfer lock','state'=>null,'actions'=>[]]],testEnvironment:true);
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$facts=RecordFamilies::read($d,$now);$page=RecordFamilies::owner($page,$facts);
$note=DomainPage::notice($page);
same('Checking with the registrar. This usually takes a few minutes.',$note['text']??null,'A new active domain has a calm pending notice');
foreach($facts['rows'] as $family){
    $html=view('domains::partials.record-family-status',compact('family'))->render();
    expect(str_contains($html,'Checking')&&!str_contains($html,'domains-missing'),'Unread '.$family['kind'].' is Checking, never Missing');
}
$html=view('domains::show',['domain'=>$page,'summary'=>null])->render();
expect(storefrontDom($html)->query('//*[contains(concat(" ",normalize-space(@class)," ")," domains-missing ")]')->length===0,'Initial domain page has no Missing marks, including transfer lock');
expect(!str_contains(strip_tags($html),'No registrar read is queued.'),'Customers do not see staff queue instructions');
$op=new Operation(['id'=>1,'action'=>'register','state'=>'succeeded','authority_generation'=>1,'account_fingerprint'=>$d->account_fingerprint,'payload'=>['nameservers'=>['ns1.example.net','ns2.example.net']],'attempt'=>['corrections'=>['nameservers'=>['state'=>'authorization_required']]],'policy'=>[]]);
same(false,RegistrationDns::pending($op,$d),'An unconfirmed registration warning does not ask for DNS repairs');
$d->observation=ObservationProjection::recordNameservers($d,NameserverEvidence::requested(['ns3.example.net','ns4.example.net'],'registration_request',$now));
same(false,RegistrationDns::pending($op,$d),'Requested nameservers cannot prove a mismatch');
$d->observation=ObservationProjection::recordNameservers($d,NameserverEvidence::observed(['ns3.example.net','ns4.example.net'],'enom_getdns',$now));
same(true,RegistrationDns::pending($op,$d),'A confirmed mismatch retains the repair path');
$d->observation=ObservationProjection::recordNameservers($d,NameserverEvidence::observed(['ns2.example.net','ns1.example.net'],'enom_getdns',$now));
same(false,RegistrationDns::pending($op,$d),'An equivalent confirmed list clears the warning');
$d->observation=['record_failure'=>['identity'=>ObservationRecords::identity($d),'code'=>'domain_not_in_account']];
$failed=RecordFamilies::owner($page,RecordFamilies::read($d,$now));
expect((DomainPage::notice($failed)['text']??null)!==($note['text']??null),'A real registrar failure cannot promise the initial pending state');
$nativeSuccessful=true;echo "Initial registrar read and confirmed nameserver mismatch passed.\n";
