<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. One status pill per domain on the owned list, detail header and service card.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/../Domains/routes/web.php';
require __DIR__.'/../Domains/routes/owned.php';
use Illuminate\Pagination\LengthAwarePaginator;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary, OwnedDomain, StatusPill};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaryStrings, OwnedDomains};
storefrontViews();
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...OwnerActionController::ACTIONS],str_repeat('a',64)));
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$strings=DomainSummaryStrings::all(); $today=gmdate('Y-m-d'); $soon=gmdate('Y-m-d',time()+10*86400); $later=gmdate('Y-m-d',time()+200*86400);
$fresh=array_fill_keys(['delegation','points','registry'],['checkedAt'=>time(),'stale'=>false,'partial'=>false,'nextCheck'=>null]);
$summary=static fn(string $name,array $states,array $extra=[])=>new DomainSummary(...[...['name'=>$name,'displayName'=>null,'kind'=>'managed','registrationKey'=>'domain.registration.platform',
    'dnsKeys'=>DnsSignalLabels::keys('external',false,false),'website'=>false,'email'=>false,'stateKeys'=>$states,'labels'=>$strings,'expiry'=>$later,'expiryPrecision'=>'date','expirySource'=>'Confirmed registration',
    'freshness'=>$fresh,'renewalDueAt'=>null,'renewalPrice'=>'$14.00','relatedServices'=>[],'hostnames'=>[],'urls'=>['detail'=>'/domains/1','support'=>'/tickets/create']],...$extra]);
// state => [summary states, summary extras, owned status, expected pill]
$cases=[
    'active'=>[['domain.active','watch.not_checked','adopt.provenance'],[],'Active','Active'],
    'expiring'=>[['domain.active','adopt.renewal_off'],['expiry'=>$soon,'renewalOff'=>true],'Active','Expiring'],
    'auto-renew-on-soon'=>[['domain.active'],['expiry'=>$soon],'Active','Active'],
    'expired'=>[['domain.expired'],['expiry'=>$today],'Expired','Expired'],
    'redemption'=>[['domain.redemption'],[],'In redemption','Expired'],
    'registry-expiry'=>[['watch.expired','adopt.provenance'],['expiry'=>$today],'Registry expiry reached','Expired'],
    'pending'=>[['domain.pending'],[],'Registration in progress','Pending'],
    'transfer'=>[['domain.transfer'],[],'Transfer in progress','Pending'],
    'adoption'=>[['adopt.pending'],[],'Being set up','Pending'],
    'processing'=>[['domain.processing'],[],'Processing','Pending'],
    'payment'=>[['domain.awaiting_payment'],[],'Awaiting payment','Needs action'],
    'review'=>[['domain.review','watch.stale'],[],'Needs attention','Needs action'],
    'moved'=>[['domain.active','watch.ns_changed'],['noticeDates'=>['dns'=>time()]],'Active','Needs action'],
    'expired-and-moved'=>[['domain.expired','watch.ns_changed'],[],'Expired','Needs action'],
    'cancelled'=>[['domain.cancelled'],[],'Order cancelled','Cancelled'],
];
$pills=static fn($dom,$context=null)=>array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('.//*[contains(concat(" ",normalize-space(@class)," ")," domains-badge ")]',$context??$dom->document->documentElement)));
$labels=array_column(StatusPill::PILLS,0);
foreach ($labels as $label) { expect(str_word_count($label)<=2 && !preg_match('/\d/',$label),'Pill text is one or two words, no dates: '.$label); }
$rows=[];
foreach ($cases as $state=>[$states,$extra,$status,$expected]) {
    $s=$summary($state.'.example',$states,$extra); $rows[]=$s;
    $list=storefrontDom(view('domains::index',['domains'=>new LengthAwarePaginator([$s],1,25)])->render());
    same([$expected],$pills($list,$list->query('//tbody/tr')->item(0)),'List row shows exactly one status pill: '.$state);
    $owned=new OwnedDomain($state.'.example',$status,'ok',$s->expiry,$s->renewalOff?'Off':'On','$14.00',['show'=>'/domains/1'],[],['contacts'=>false,'required'=>false],[],[],true);
    $detail=storefrontDom(view('domains::show',['domain'=>$owned,'summary'=>$s])->render());
    same([$expected],$pills($detail),'Detail page shows exactly one status pill: '.$state);
    same(1,$detail->query('//div[@class="domains-dhead__meta"]/span[contains(@class,"domains-status")]')->length,'The pill sits in the detail header: '.$state);
    if (!in_array($state,['moved','expired-and-moved'],true)) { same([$expected],$pills(storefrontDom(view('domains::service',['domain'=>$owned])->render())),'Service card shows the same pill: '.$state); }
    $pill=StatusPill::summary($s,$today);
    same(array_column(StatusPill::PILLS,1,0)[$expected],$pill['tone'],'Pill tone follows its label: '.$state);
}
$list=storefrontDom(view('domains::index',['domains'=>new LengthAwarePaginator($rows,count($rows),25)])->render());
same(count($rows),count($pills($list)),'The full list shows one pill per domain and nothing else');
same(array_column($cases,3),$pills($list),'List pills follow precedence');
// Unknown evidence never earns a pill: a watch needs current registry facts and a known expiry to read Active.
$watch=static fn(array $states,array $extra=[])=>$summary('watch.example',$states,['kind'=>'watch','registrationKey'=>'domain.registration.external','renewalPrice'=>null,...$extra]);
same(['label'=>'Active','tone'=>'ok'],StatusPill::summary($watch([])),'Current registry facts read Active');
same(null,StatusPill::summary($watch(['watch.not_checked'],['freshness'=>array_map(fn($f)=>[...$f,'checkedAt'=>null,'stale'=>true],$fresh)])),'Never checked watch has no pill');
same(null,StatusPill::summary($watch(['watch.stale'],['freshness'=>array_map(fn($f)=>[...$f,'stale'=>true],$fresh)])),'Out of date watch has no pill');
same(null,StatusPill::summary($watch([],['expiry'=>null])),'Unknown expiry has no pill');
same('Checks paused',StatusPill::summary($watch(['watch.paused'],['freshness'=>array_map(fn($f)=>[...$f,'stale'=>true],$fresh)]))['label'],'Paused checks say so');
// Detail pages carry no other pills: management sections, notices and check details are plain text.
$m=['nameservers'=>['label'=>'Nameservers','url'=>'/n','actions'=>['nameservers'=>'/n'],'max'=>12,'defaults'=>true,'current'=>['ns1.example.net','ns2.example.net'],'defaultServers'=>OwnedDomains::DEFAULT_NAMESERVERS],
    'lock'=>['label'=>'Transfer lock','url'=>'/l','actions'=>['lock'=>'/l']],'privacy'=>['label'=>'Domain privacy','url'=>'/p','free'=>true,'price'=>null,'actions'=>['privacy_enable'=>'/e','privacy_disable'=>'/x']]];
$full=new OwnedDomain('full.example','Active','ok',$later,'On','$14.00',['show'=>'/domains/1'],[],['contacts'=>false,'required'=>false],[],$m,true,autoRenew:['url'=>'/a','enabled'=>true]);
same(['Active'],$pills(storefrontDom(view('domains::show',['domain'=>$full,'summary'=>$summary('full.example',['domain.active','adopt.provenance','adopt.renewal_off'])])->render())),'Managed detail with every section still shows one pill');
$nativeSuccessful=true;
