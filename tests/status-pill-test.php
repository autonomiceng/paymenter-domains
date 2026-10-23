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
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus, DomainSummary, OwnedDomain, StatusPill};
use Paymenter\Extensions\Servers\Domains\Services\{DomainStates, DomainSummaryStrings, OwnedDomains};
storefrontViews();
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...OwnerActionController::ACTIONS],str_repeat('a',64)));
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$strings=DomainSummaryStrings::all(); $today=gmdate('Y-m-d'); $soon=gmdate('Y-m-d',time()+10*86400); $later=gmdate('Y-m-d',time()+200*86400);
$fresh=array_fill_keys(['delegation','points','registry'],['checkedAt'=>time(),'stale'=>false,'partial'=>false,'nextCheck'=>null]);
$summary=static fn(string $name,array $states,array $extra=[])=>new DomainSummary(...[...['name'=>$name,'displayName'=>null,'kind'=>'managed','registrationKey'=>'domain.registration.platform',
    'dnsKeys'=>DnsSignalLabels::keys('external',false,false),'website'=>false,'email'=>false,'stateKeys'=>$states,'labels'=>$strings,'expiry'=>$later,'expiryPrecision'=>'date','expirySource'=>'Confirmed registration',
    'freshness'=>$fresh,'renewalDueAt'=>null,'renewalPrice'=>'$14.00','relatedServices'=>[],'hostnames'=>[],'urls'=>['detail'=>'/domains/1','support'=>'/tickets/create']],...$extra]);
// case => [summary states, summary extras, resolved state, expected pill, expected reason]
$cases=[
    'active'=>[['domain.active','watch.not_checked','adopt.provenance'],[],'active','Active',null],
    'expiring'=>[['domain.active','adopt.renewal_off'],['expiry'=>$soon,'renewalOff'=>true],'renewal_off_expiring','Action required','Auto-renew off'],
    'auto-renew-on-soon'=>[['domain.active'],['expiry'=>$soon],'active','Active',null],
    'no-expiry'=>[['domain.review'],['expiry'=>null],'active_no_expiry','Under review','Expiry missing'],
    'expired'=>[['domain.expired'],['expiry'=>$today],'expired','Expired',null],
    'grace'=>[['domain.expired'],['expiry'=>$today],'grace','Expired','In grace period'],
    'redemption'=>[['domain.redemption'],[],'redemption','Redemption',null],
    'registry-expiry'=>[['watch.expired','adopt.provenance'],['expiry'=>$today],'expired','Expired',null],
    'pending'=>[['domain.pending'],[],'registration_pending','Pending registration',null],
    'transfer'=>[['domain.transfer'],[],'transfer_pending','Pending transfer',null],
    'adoption'=>[['adopt.pending'],[],'adoption_pending','Under review','Import not confirmed'],
    'processing'=>[['domain.processing'],[],'registration_pending','Pending registration',null],
    'setup'=>[['domain.processing'],[],'setup_required','Action required','Finish setup'],
    'payment'=>[['domain.awaiting_payment'],[],'awaiting_payment','Action required','Invoice not paid'],
    'review'=>[['domain.review','watch.stale'],[],'needs_review','Under review',null],
    'failed'=>[['domain.review'],[],'registration_rejected','Failed','Registration rejected'],
    // A managed domain's nameserver change is a notice on its page, not its status.
    'moved'=>[['domain.active','watch.ns_changed'],['noticeDates'=>['dns'=>time()]],'active','Active',null],
    'expired-and-moved'=>[['domain.expired','watch.ns_changed'],[],'expired','Expired',null],
    'cancelled'=>[['domain.cancelled'],[],'cancelled','Cancelled',null],
];
$pills=static fn($dom,$context=null)=>array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('.//*[contains(concat(" ",normalize-space(@class)," ")," domains-badge ")]',$context??$dom->document->documentElement)));
$reasons=static fn($dom,$context=null)=>array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('.//*[contains(concat(" ",normalize-space(@class)," ")," domains-pill-reason ")]',$context??$dom->document->documentElement)));
$tones=static fn($dom)=>array_map(fn($n)=>preg_replace('/.*domains-tone-(\w+).*/','$1',$n->getAttribute('class')),iterator_to_array($dom->query('//*[contains(concat(" ",normalize-space(@class)," ")," domains-badge ")]')));
foreach (array_column(DomainStatus::SET,0) as $label) { expect(str_word_count($label)<=2 && !preg_match('/\d/',$label),'Pill text is one or two words, no dates: '.$label); }
foreach (['Expiring','Pending','Needs action','Needs attention','Checks paused','Watch ended','Being set up','In redemption'] as $old) { expect(!in_array($old,array_column(DomainStatus::SET,0),true),'Old pill label is gone: '.$old); }
$rows=[];
foreach ($cases as $state=>[$states,$extra,$resolved,$expected,$reason]) {
    $s=$summary($state.'.example',$states,[...$extra,'statusState'=>$resolved]); $rows[]=$s;
    $pill=StatusPill::of($resolved);
    same([$expected,$reason],[$pill['label'],$pill['reason']],'Status set names the state: '.$state);
    same(StatusPill::TONES[DomainStatus::SET[DomainStatus::key($resolved)][1]],$pill['tone'],'Pill tone follows its label: '.$state);
    $list=storefrontDom(view('domains::index',['domains'=>new LengthAwarePaginator([$s],1,25)])->render());
    same([$expected],$pills($list,$list->query('//tbody/tr')->item(0)),'List row shows exactly one status pill: '.$state);
    same($reason===null?[]:[$reason],$reasons($list,$list->query('//tbody/tr')->item(0)),'List row shows the reason under the pill: '.$state);
    $owned=new OwnedDomain($state.'.example',$pill['label'],$pill['tone'],$s->expiry,$s->renewalOff?'Off':'On','$14.00',['show'=>'/domains/1'],[],['contacts'=>false,'required'=>false],[],[],true,statusReason:$pill['reason']);
    $detail=storefrontDom(view('domains::show',['domain'=>$owned,'summary'=>$s])->render());
    same([$expected],$pills($detail),'Detail page shows exactly one status pill: '.$state);
    same([$pill['tone']],$tones($detail),'Detail pill keeps its tone: '.$state);
    same(1,$detail->query('//div[@class="domains-dhead__meta"]/span[contains(concat(" ",@class," ")," domains-status ")]')->length,'The pill sits in the detail header: '.$state);
    $card=storefrontDom(view('domains::service',['domain'=>$owned])->render());
    same([[$expected],$reason===null?[]:[$reason]],[$pills($card),$reasons($card)],'Service card shows the same pill and reason: '.$state);
}
$list=storefrontDom(view('domains::index',['domains'=>new LengthAwarePaginator($rows,count($rows),25)])->render());
same(count($rows),count($pills($list)),'The full list shows one pill per domain and nothing else');
same(array_column($cases,3),$pills($list),'List pills follow the resolved states');
// Unknown evidence never earns a pill: a watch needs current registry facts and a known expiry to read Active.
$watch=static function(array $states,array $extra=[])use($summary,$fresh){
    $extra=[...['freshness'=>$fresh,'expiry'=>gmdate('Y-m-d',time()+200*86400)],...$extra];
    $status=DomainStates::watch($states,$extra['expiry'],!$extra['freshness']['registry']['stale']);
    return $summary('watch.example',$states,['kind'=>'watch','registrationKey'=>'domain.registration.external','renewalPrice'=>null,'statusState'=>$status[0]??null,'statusReason'=>$status[1]??null,...$extra]);
};
same(['label'=>'Active','tone'=>'ok','reason'=>null],StatusPill::summary($watch([])),'Current registry facts read Active');
same(null,StatusPill::summary($watch(['watch.not_checked'],['freshness'=>array_map(fn($f)=>[...$f,'checkedAt'=>null,'stale'=>true],$fresh)])),'Never checked watch has no pill');
same(null,StatusPill::summary($watch(['watch.stale'],['freshness'=>array_map(fn($f)=>[...$f,'stale'=>true],$fresh)])),'Out of date watch has no pill');
same(null,StatusPill::summary($watch([],['expiry'=>null])),'Unknown expiry has no pill');
same(null,StatusPill::summary($watch(['watch.paused'],['freshness'=>array_map(fn($f)=>[...$f,'stale'=>true],$fresh)])),'Paused checks show no pill; the page says checks are paused');
same(['label'=>'Action required','tone'=>'warn','reason'=>'Not registered'],StatusPill::summary($watch(['watch.not_registered'])),'Not registered asks the customer to act');
same(['label'=>'Redemption','tone'=>'bad','reason'=>null],StatusPill::summary($watch(['watch.redemption','watch.expired'])),'Redemption outranks expiry');
same(['label'=>'Expired','tone'=>'bad','reason'=>'Renew at your registrar'],StatusPill::summary($watch(['watch.expired','watch.ns_changed'])),'Expiry outranks a nameserver change');
same('Action required',StatusPill::summary($watch(['watch.ns_changed']))['label'],'A monitored domain off our nameservers asks the customer to act');
// Detail pages carry no other pills: management sections, notices and check details are plain text.
$m=['nameservers'=>['label'=>'Nameservers','url'=>'/n','actions'=>['nameservers'=>'/n'],'max'=>12,'defaults'=>true,'current'=>['ns1.example.net','ns2.example.net'],'defaultServers'=>OwnedDomains::DEFAULT_NAMESERVERS],
    'lock'=>['label'=>'Transfer lock','url'=>'/l','actions'=>['lock'=>'/l']],'privacy'=>['label'=>'Domain privacy','url'=>'/p','free'=>true,'price'=>null,'actions'=>['privacy_enable'=>'/e','privacy_disable'=>'/x']]];
$full=new OwnedDomain('full.example','Active','ok',$later,'On','$14.00',['show'=>'/domains/1'],[],['contacts'=>false,'required'=>false],[],$m,true,autoRenew:['url'=>'/a','enabled'=>true]);
same(['Active'],$pills(storefrontDom(view('domains::show',['domain'=>$full,'summary'=>$summary('full.example',['domain.active','adopt.provenance','adopt.renewal_off'])])->render())),'Managed detail with every section still shows one pill');
$nativeSuccessful=true;
