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
$d=new Domain(['id'=>73,'domain'=>'new-placeholder.com','status'=>'active','registrar_key'=>'enom','server_id'=>1,'authority_generation'=>1,'account_fingerprint'=>str_repeat('a',64),'observation'=>[]]);
$page=new OwnedDomain(name:$d->domain,status:'Active',tone:'ok',expiry:'2027-11-07',renewal:'On',renewalPrice:'$10.99',actions:['show'=>'/domains/73'],progress:[],setup:[],transfer:[],management:['lock'=>['label'=>'Transfer lock','state'=>null,'actions'=>[]]],testEnvironment:true);
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
// The first registrar read is real queued work for this holding.
$work=static function(?array $row)use($d,$now):void {
    Illuminate\Support\Facades\DB::table('domains_observation_work')->delete();
    if($row!==null){Illuminate\Support\Facades\DB::table('domains_observation_work')->insert([...['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,
        'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>'holding','due_at'=>$now->getTimestamp()],...$row]);}
};
// Each family renders alone, so a Checking or Missing mark is its own and not the page notice.
$families=static function(array $facts,string $case,bool $checking):void {
    foreach($facts['rows'] as $family){
        $html=view('domains::partials.record-family-status',compact('family'))->render();
        same($checking,str_contains($html,'Checking'),$case.': '.$family['kind'].' Checking');
        same(!$checking,str_contains($html,DomainPage::NOT_READ),$case.': '.$family['kind'].' Not read yet');
    }
};
$work([]);
$facts=RecordFamilies::read($d,$now);$initial=RecordFamilies::owner($page,$facts);
$note=DomainPage::notice($initial);
same(DomainPage::INITIAL_READ,$note['text']??null,'A new active domain has a calm pending notice');
$families($facts,'Queued first read',true);
$html=view('domains::show',['domain'=>$initial,'summary'=>null])->render();
expect(storefrontDom($html)->query('//*[contains(concat(" ",normalize-space(@class)," ")," domains-missing ")]')->length===0,'Initial domain page has no Missing marks, including transfer lock');
expect(!str_contains(strip_tags($html),'No registrar read is queued.'),'Customers do not see staff queue instructions');
// A row that says Checking has nothing current to change: its action is shown turned off until the read finishes.
$lockPage=new OwnedDomain(name:$d->domain,status:'Active',tone:'ok',expiry:'2027-11-07',renewal:'On',renewalPrice:'$10.99',actions:['show'=>'/domains/73'],progress:[],setup:[],transfer:[],
    management:['lock'=>['label'=>'Transfer lock','state'=>null,'url'=>'/domains/73/lock','actions'=>['lock'=>'/domains/73/lock']],
        'nameservers'=>['label'=>'Nameservers','current'=>['ns1.example.net','ns2.example.net'],'url'=>'/domains/73/nameservers','actions'=>['nameservers'=>'/domains/73/nameservers']]],testEnvironment:true);
$dom=storefrontDom(view('domains::show',['domain'=>RecordFamilies::owner($lockPage,$facts),'summary'=>null,'keys'=>['lock'=>'fixture-key','nameservers'=>'fixture-key']])->render());
same(0,$dom->query('//li[@id="lock"]//a[contains(@href,"edit=lock")]')->length,'Checking lock row has no live Change link');
same(1,$dom->query('//li[@id="lock"]//button[@disabled and normalize-space(.)="Change"]')->length,'Checking lock row shows Change turned off');
// Nameservers we sent at registration still show, but the row says Checking, so Edit waits too.
expect(str_contains((string)$dom->query('//li[@id="nameservers"]')->item(0)?->textContent,'Checking'),'Fixture: the nameserver row says Checking');
same(1,$dom->query('//li[@id="nameservers"]//button[@disabled and normalize-space(.)="Edit"]')->length,'A Checking nameserver row shows Edit turned off');
expect(str_contains(DomainPage::INITIAL_READ,'Changes open when the check finishes.'),'The notice says why changes are off');
// Only queued work that is on time can promise the first read. Otherwise each family is Missing.
foreach(['no queued read'=>[null,false],'running read'=>[['due_at'=>$now->getTimestamp()-7200,'lease_until'=>$now->getTimestamp()+300,'claim'=>str_repeat('c',32)],true],
    'overdue read'=>[['due_at'=>$now->getTimestamp()-7200],false],'paused records'=>[['safe_code'=>'records_disabled'],false],
    'stopped read'=>[['safe_code'=>'read_unavailable','tries'=>5],false]] as $case=>[$row,$checking]){
    $work($row);$facts=RecordFamilies::read($d,$now);
    same($checking,$facts['initial_read_pending'],$case.' sets the first-read promise');
    same($checking,(DomainPage::notice(RecordFamilies::owner($page,$facts))['text']??null)===DomainPage::INITIAL_READ,$case.' sets the first-read notice');
    $families($facts,ucfirst($case),$checking);
}
// Staff: a read that is due but not started waits in the account's read queue; the line does not show a past time as the next read.
$work(['due_at'=>$now->getTimestamp()-600]);$queued=RecordFamilies::read($d,$now);
same(true,$queued['read_queued'],'A due read that has not started is queued');
$staffLine=storefrontDom(view('domains::partials.record-families',['recordFamilies'=>$queued,'staff'=>true])->render())->query('//p[starts-with(normalize-space(.),"Next registrar read:")]');
expect($staffLine->length===1&&str_contains($staffLine->item(0)->textContent,'Waiting its turn in the account’s read queue, due since'),'Staff read that a due read waits its turn');
$work([]);
$op=new Operation(['id'=>1,'action'=>'register','state'=>'succeeded','authority_generation'=>1,'account_fingerprint'=>$d->account_fingerprint,'payload'=>['nameservers'=>['ns1.example.net','ns2.example.net']],'attempt'=>['corrections'=>['nameservers'=>['state'=>'authorization_required']]],'policy'=>[]]);
same(false,RegistrationDns::pending($op,$d),'An unconfirmed registration warning does not ask for DNS repairs');
$d->observation=ObservationProjection::recordNameservers($d,NameserverEvidence::requested(['ns3.example.net','ns4.example.net'],'registration_request',$now));
same(false,RegistrationDns::pending($op,$d),'Requested nameservers cannot prove a mismatch');
$d->observation=ObservationProjection::recordNameservers($d,NameserverEvidence::observed(['ns3.example.net','ns4.example.net'],'enom_getdns',$now));
same(true,RegistrationDns::pending($op,$d),'A confirmed mismatch retains the repair path');
$d->observation=ObservationProjection::recordNameservers($d,NameserverEvidence::observed(['ns2.example.net','ns1.example.net'],'enom_getdns',$now));
same(false,RegistrationDns::pending($op,$d),'An equivalent confirmed list clears the warning');
$d->observation=['record_failure'=>['identity'=>ObservationRecords::identity($d),'code'=>'domain_not_in_account']];
$facts=RecordFamilies::read($d,$now);$failed=RecordFamilies::owner($page,$facts);
expect((DomainPage::notice($failed)['text']??null)!==($note['text']??null),'A real registrar failure cannot promise the initial pending state');
$families($facts,'Failed read with queued work',false);
$nativeSuccessful=true;echo "Initial registrar read and confirmed nameserver mismatch passed.\n";
