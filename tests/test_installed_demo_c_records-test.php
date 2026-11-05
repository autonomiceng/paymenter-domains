<?php
// Native family display over encrypted domain casts. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\ObservationRecords;
use Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies;
(new Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
storefrontViews();require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$now=new DateTimeImmutable('2026-11-05T12:00:00+00:00');
$d=new Domain(['id'=>1,'domain'=>'orbit-example.com','registrar_key'=>'namesilo','server_id'=>1,'authority_generation'=>1,'account_fingerprint'=>str_repeat('a',64),'observation'=>[]]);
$record=['identity'=>ObservationRecords::identity($d),'at'=>'2026-11-04T09:00:00+00:00','sequence'=>0,'format'=>1,'capability_revision'=>'namesilo-records-1',
    'expected_families'=>['dns'=>'supported','srv'=>'unsupported','ds'=>'supported','glue'=>'supported'],
    'families'=>['dns'=>['state'=>'complete','records'=>[['name'=>'@','type'=>'CAA','address'=>'0:issue:ca.example','mx_pref'=>10,'record_id'=>'123','ttl'=>3600]]],
        'srv'=>['state'=>'unknown','records'=>[]],'ds'=>['state'=>'unknown','records'=>[]],'glue'=>['state'=>'partial','records'=>[]]],
    'settings'=>['nameservers'=>['state'=>'complete','facts'=>['nameservers'=>['ns1.example.net','ns2.example.net']]],'contacts'=>['state'=>'unknown','facts'=>[]],'flags'=>['state'=>'complete','facts'=>[]]]];
$d->observation=['records'=>$record];$before=$d->getRawOriginal();$state=RecordFamilies::read($d,$now);
$families=array_column($state['rows'],'state','kind');
same(['complete','unsupported','unknown','partial'],[$families['dns'],$families['srv'],$families['ds'],$families['glue']],'Each record family keeps its own state');
expect($state['stale'],'Older than one day is stale');
$owned=new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(name:$d->domain,status:'Active',tone:'ok',expiry:'2027-11-20',renewal:'On',renewalPrice:'$14.00',actions:['show'=>'/domains/1'],progress:[],setup:[],transfer:[],management:[],testEnvironment:false);
$page=RecordFamilies::owner($owned,$state);
$html=view('domains::partials.owner-management',['domain'=>$page])->render();
foreach(['Complete','Partial','Missing','Unsupported','CAA','3600','Read-only','Checked'] as $text){expect(str_contains($html,$text),'Customer sees '.$text);}
expect(str_contains($html,'aria-label="DNS records"')&&str_contains($html,'TTL'),'CAA is in the DNS records table');
$caaDom=storefrontDom($html);
same(1,$caaDom->query('//tr[td[normalize-space(.)="CAA"]]')->length,'The real DNS table contains the CAA row');
same(0,$caaDom->query('//tr[td[normalize-space(.)="CAA"]]//input | //tr[td[normalize-space(.)="CAA"]]//button')->length,'CAA row has no edit or delete controls');
$section=['label'=>'DNS records','records'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.10','mx_pref'=>10]],'fixed'=>[['name'=>'@','type'=>'CAA','address'=>'0:issue:ca.example','ttl'=>3600]],'actions'=>['dns'=>'/domains/1/dns'],'digest'=>str_repeat('a',64)];
$editHtml=view('domains::partials.owner-dns',['domain'=>$page,'section'=>$section,'cap'=>'dns','keys'=>['dns'=>str_repeat('a',64)]])->render();$editDom=storefrontDom($editHtml);
same(1,$editDom->query('//table[contains(@class,"domains-records")]')->length,'Editable and fixed DNS rows share one table');
same(1,$editDom->query('//tr[td[normalize-space(.)="CAA"] and td[normalize-space(.)="3600"]]')->length,'The shared DNS table keeps the CAA TTL');
same(0,$editDom->query('//tr[td[normalize-space(.)="CAA"]]//input | //tr[td[normalize-space(.)="CAA"]]//button')->length,'The CAA sibling has no mutation controls');
expect(!str_contains($html,'call limit')&&!str_contains($html,'Resume refresh'),'Customer has no staff recovery chore');
$s=$d->observation;$s['records']['settings']['contacts']=['state'=>'partial','facts'=>['registrant'=>['first_name'=>'Test']]];$s['records']['last_complete_at']='2026-11-03T09:00:00+00:00';$s['record_hold']=['identity'=>ObservationRecords::identity($d),'reason'=>'budget_exhausted','family'=>'dns'];$d->observation=$s;
$state=RecordFamilies::read($d,$now);$html=view('domains::partials.record-families',['recordFamilies'=>$state,'staff'=>true])->render();
expect(str_contains($html,'Registrant returned; some contacts did not.'),'Staff names the returned contact role');
expect(str_contains($html,'Refresh contacts before editing.'),'Staff gets a contacts-specific next step');
expect(str_contains($html,'Last complete read:'),'Staff sees the last complete timestamp');
expect(str_contains($html,'Failed family: dns')&&str_contains($html,'Resume refresh'),'Staff sees failed family and resume step');
expect(str_contains($html,RecordFamilies::EVIDENCE),'Staff sees public versus registrar evidence distinction');
$s=$d->observation;$s['records']['families']['dns']=['state'=>'unknown','records'=>[],'last_good'=>['at'=>'2026-11-03T09:00:00+00:00','records'=>$record['families']['dns']['records']]];$d->observation=$s;
$state=RecordFamilies::read($d,$now);$html=view('domains::partials.record-families',['recordFamilies'=>$state])->render();
same('unknown',array_column($state['rows'],'state','kind')['dns'],'Historical CAA does not restore completeness');
expect(str_contains($html,'ca.example')&&str_contains($html,'Last confirmed CAA:')&&str_contains($html,'historical records'),'A failed read retains explicitly historical CAA');
$s['records']['families']['dns']['state']='complete';$d->observation=$s;
same([],RecordFamilies::read($d,$now)['caa'],'A confirmed empty read does not resurrect historical CAA');
// An incomplete family must not hide an already pending operation's reviewed status.
$pendingPage=new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(name:'example.com',status:'Active',tone:'ok',expiry:null,renewal:'On',renewalPrice:null,actions:['show'=>'/domains/1'],progress:[],setup:[],transfer:[],management:['ds'=>['label'=>'DNSSEC','family_only'=>true,'family'=>['value'=>'Unknown','state'=>'unknown','customer'=>'Checking records','at'=>null,'last_good_at'=>null],'actions'=>[],'progress'=>['pending'=>true,'id'=>42,'state'=>'Unknown','text'=>'Nothing was sent.','tone'=>'info']]],testEnvironment:false);
$pendingHtml=view('domains::partials.owner-management',['domain'=>$pendingPage])->render();expect(str_contains($pendingHtml,'data-ds-state')&&str_contains($pendingHtml,'Nothing was sent.'),'Partial family metadata preserves pending DS status');
// The family presentation can restrict a current read, but cannot revoke existing controls without one.
$existing=[];foreach(['nameservers','contacts','dns','srv','glue','ds'] as $kind){$existing[$kind]=['label'=>$kind,'actions'=>[$kind=>'/domains/1/manage/'.$kind],'url'=>'/domains/1/manage/'.$kind,'records'=>[],'fixed'=>[]];}
$existing['nameservers']+=['current'=>['ns1.example.net','ns2.example.net'],'max'=>13,'defaults'=>false];
$existing['dns']=[...$section,'url'=>'/domains/1/dns'];
$editable=new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(...[...get_object_vars($owned),'management'=>$existing]);
foreach(['never_read','wrong_identity','failed_read'] as $case){
    $probe=clone $d;$observation=$probe->observation;
    if($case==='never_read'){unset($observation['records']);}
    elseif($case==='wrong_identity'){$observation['records']['identity']['generation']++;}
    else{$observation['record_failure']=['identity'=>ObservationRecords::identity($probe),'at'=>$now->format(DATE_ATOM),'code'=>'read_failed'];}
    $probe->observation=$observation;$facts=RecordFamilies::read($probe,$now);$projected=RecordFamilies::owner($editable,$facts);
    foreach($existing as $kind=>$section){$actual=$projected->management[$kind];unset($actual['family']);same($section,$actual,$case.' preserves the existing '.$kind.' section and its authority');}
    same('Unknown',$projected->management['dns']['family']['value'],$case.' still shows the unknown family state');
    $ownerHtml=view('domains::partials.owner-management',['domain'=>$projected,'caps'=>['nameservers','dns'],'keys'=>['nameservers'=>str_repeat('a',64),'dns'=>str_repeat('b',64)]])->render();$ownerDom=storefrontDom($ownerHtml);
    same(1,$ownerDom->query('//form[contains(@action,"/manage/nameservers")]')->length,$case.' keeps the supplied nameserver editor on the owner page');
    same(1,$ownerDom->query('//form[@action="/domains/1/dns"]')->length,$case.' keeps the supplied DNS editor on the owner page');
}
$partial=RecordFamilies::owner($editable,RecordFamilies::read($d,$now));same([],$partial->management['contacts']['actions'],'A current partial contact read still holds whole-list editing');
$unknown=new Domain(['domain'=>'example.com','registrar_key'=>'enom','server_id'=>1,'authority_generation'=>1,'account_fingerprint'=>str_repeat('a',64),'observation'=>[]]);
$unreadManagement=$existing;foreach(['dns','srv','ds'] as $key){$unreadManagement[$key]['records']=null;$unreadManagement[$key]['actions']=[];}
$unread=RecordFamilies::owner(new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(...[...get_object_vars($owned),'management'=>$unreadManagement]),RecordFamilies::read($unknown,$now));
$unreadHtml=view('domains::partials.owner-management',['domain'=>$unread,'caps'=>['dns','srv','ds'],'keys'=>array_fill_keys(['dns','srv','ds','ds_delete'],str_repeat('b',64))])->render();
expect(!str_contains($unreadHtml,'Contact support to sync')&&!str_contains($unreadHtml,'domains-missing'),'Unknown registrar families show checking without a red Missing or support chore');
expect(str_contains($unreadHtml,'checking'),'Unknown family keeps its checking explanation');
// Enom partial glue is a bounded known-host check, not a promise of enumeration.
$enom=clone $d;$enom->registrar_key='enom';$r=$record;$r['capability_revision']='enom-records-1';$r['identity']=ObservationRecords::identity($enom);$r['families']['glue']['candidates']=['ns1.orbit-example.com','ns2.orbit-example.com'];$enom->observation=['records'=>$r];
$g=array_column(RecordFamilies::read($enom,$now)['rows'],null,'kind')['glue'];
expect(str_contains($g['staff'],"Known glue checked; this registrar can't list every child host"),'Enom known glue explains bounded coverage');
expect(str_contains($g['staff'],'2 known child hosts')&&$g['at']==='2026-11-04T09:00:00+00:00','Coverage and checked time shown');
expect(!str_contains($g['staff'],'some did not')&&!str_contains($g['staff'],'Sync this domain before editing'),'Impossible glue prerequisite removed');
$safe=$existing;$safe['glue']['actions']=['glue_add'=>'/add','glue_update'=>'/update','glue_delete'=>'/delete','glue'=>'/replace'];
$projected=RecordFamilies::owner(new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(...[...get_object_vars($owned),'management'=>$safe]),RecordFamilies::read($enom,$now));
same(['glue_add','glue_update','glue_delete'],array_keys($projected->management['glue']['actions']),'Partial glue preserves individual controls and blocks whole-list replacement');
// NameSilo retains its base partial-glue restrictions; only Enom has the enumeration-limit exception.
$namesiloFacts=RecordFamilies::read($d,$now);
$namesilo=RecordFamilies::owner(new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(...[...get_object_vars($owned),'management'=>$safe]),$namesiloFacts);
$reviewFailures=[];if($namesilo->management['glue']['actions']!==[]){$reviewFailures[]='NameSilo partial glue gained individual controls';}
$customer=view('domains::partials.record-families',['recordFamilies'=>RecordFamilies::read($enom,$now)])->render();
$dom=storefrontDom($customer);
if($dom->query('//dd[span[@data-record-family="glue"]]//time[@data-domains-time and @datetime="2026-11-04T09:00:00+00:00"]')->length!==1){$reviewFailures[]='Customer glue lacks one local formatted date';}
if(str_contains($customer,'whole-list replacement')||str_contains($customer,'preflight')){$reviewFailures[]='Customer glue contains staff-only words';}
expect($reviewFailures===[],implode('; ',$reviewFailures));
// Cancellation alone never proves no send. Reuse the engine's terminal no-send proof.
$cancelled=clone $enom;$cancelled->status='cancelled';$cancelled->observation=[];$cancelled->service_id=1;
$op=new Paymenter\Extensions\Servers\Domains\Models\Operation(['domain_id'=>$cancelled->id,'service_id'=>1,'server_id'=>$cancelled->server_id,'registrar_key'=>'enom','account_fingerprint'=>$cancelled->account_fingerprint,'authority_generation'=>1,'action'=>'register','funding_basis'=>'paid_invoice','state'=>'rejected','safe_code'=>'acquisition_cancelled_unpaid','completed_at'=>$now,'funding'=>[],'cost'=>[],'policy'=>[]]);
$op->attempt=Paymenter\Extensions\Servers\Domains\Services\UnpaidAcquisitionProof::attempt($op);$cancelled->setRelation('operations',collect([$op]));
$facts=RecordFamilies::read($cancelled,$now);$cancelHtml=view('domains::partials.record-families',['recordFamilies'=>$facts,'staff'=>true])->render();
expect(str_contains($cancelHtml,'Registration cancelled; no registrar records to check')&&!str_contains($cancelHtml,'Sync this domain'),'No-send cancellation has no fictional inventory');
$op->attempt=['seal'=>'sealed','send_state'=>'possibly_sent'];
expect(!str_contains(json_encode(RecordFamilies::read($cancelled,$now)),'Registration cancelled; no registrar records to check'),'Possible send cannot use cancellation explanation');
$cancelled->observation=['records'=>$r];
expect(RecordFamilies::read($cancelled,$now)['at']!==null,'Cancellation preserves real historical inventory');
$nativeSuccessful=true;echo "Demo C record-family UI passed.\n";
