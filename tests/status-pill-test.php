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
$summary=static fn(string $name,array $states,array $extra=[])=>new DomainSummary(...[...['name'=>$name,'displayName'=>null,'kind'=>'managed','registrationKey'=>'domain.registration.platform','stateKeys'=>$states,'labels'=>$strings,'expiry'=>$later,'expiryPrecision'=>'date','expirySource'=>'Confirmed registration','renewalDueAt'=>null,'renewalPrice'=>'$14.00','urls'=>['detail'=>'/domains/1','support'=>'/tickets/create']],...$extra]);
// case => [summary states, summary extras, resolved state, expected pill, expected reason]
$cases=[
    'active'=>[['domain.active','watch.not_checked','adopt.provenance'],[],'active','Active',null],
    'expiring'=>[['domain.active','adopt.renewal_off'],['expiry'=>$soon,'renewalOff'=>true],'renewal_off_expiring','Action required','Auto-renew off'],
    'auto-renew-on-soon'=>[['domain.active'],['expiry'=>$soon],'active','Active',null],
    'no-expiry'=>[['domain.review'],['expiry'=>null],'active_no_expiry','Under review','Expiry missing'],
    'expired'=>[['domain.expired'],['expiry'=>$today],'expired','Expired',DomainStatus::LATE],
    'grace'=>[['domain.expired'],['expiry'=>$today],'grace','Expired',DomainStatus::LATE],
    'redemption'=>[['domain.redemption'],[],'redemption','Redemption',DomainStatus::LATE],
    'registry-expiry'=>[['watch.expired','adopt.provenance'],['expiry'=>$today],'expired','Expired',DomainStatus::LATE],
    'pending'=>[['domain.pending'],[],'registration_pending','Pending registration',null],
    'transfer'=>[['domain.transfer'],[],'transfer_pending','Pending transfer',null],
    'adoption'=>[['adopt.pending'],[],'adoption_pending','Under review','Import not confirmed'],
    'processing'=>[['domain.processing'],[],'registration_pending','Pending registration',null],
    'setup'=>[['domain.processing'],[],'setup_required','Action required','Finish setup'],
    'payment'=>[['domain.awaiting_payment'],[],'awaiting_payment','Awaiting payment','Invoice not paid'],
    'review'=>[['domain.review','watch.stale'],[],'needs_review','Under review',null],
    'failed'=>[['domain.review'],[],'registration_rejected','Failed','Registration did not finish'],
    // A managed domain's nameserver change is a notice on its page, not its status.
    'expired-and-moved'=>[['domain.expired','watch.ns_changed'],[],'expired','Expired',DomainStatus::LATE],
    'cancelled'=>[['domain.cancelled'],[],'cancelled','Cancelled',null],
];
// A status is a pill on the service card, and a State value in the list's Status cell and the detail head line.
$pills=static fn($dom,$context=null)=>array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('.//*[contains(concat(" ",normalize-space(@class)," ")," domains-badge ")] | .//td[@data-label="Status"]//*[@data-domains-tone] | .//*[@data-piece="head-line"]//*[@data-domains-tone]',$context??$dom->document->documentElement)));
$reasons=static fn($dom,$context=null)=>array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('.//*[contains(concat(" ",normalize-space(@class)," ")," domains-pill-reason ")] | .//td[@data-label="Status"]//*[contains(@class,"domains-panel-sub")]',$context??$dom->document->documentElement)));
$rows=[];
foreach ($cases as $state=>[$states,$extra,$resolved,$expected,$reason]) {
    $s=$summary($state.'.example',$states,[...$extra,'statusState'=>$resolved]); $rows[]=$s;
    $pill=StatusPill::of($resolved);
    $list=storefrontDom(view('domains::index',['domains'=>new LengthAwarePaginator([$s],1,25)])->render());
    same([$expected],$pills($list,$list->query('//tbody/tr')->item(0)),'List row shows exactly one status pill: '.$state);
    same($reason===null?[]:[$reason],$reasons($list,$list->query('//tbody/tr')->item(0)),'List row shows the reason under the pill: '.$state);
    $owned=new OwnedDomain($state.'.example',$pill['label'],$pill['tone'],$s->expiry,$s->renewalOff?'Off':'On','$14.00',['show'=>'/domains/1'],[],['contacts'=>false,'required'=>false],[],[],true,statusReason:$pill['reason']);
    $detail=storefrontDom(view('domains::show',['domain'=>$owned,'summary'=>$s])->render());
    same([$expected],$pills($detail),'Detail page shows exactly one status pill: '.$state);
    same([\Paymenter\Extensions\Servers\Domains\Presentation\CustomerValues::labelled($expected)?->tone?->value],array_map(fn($n)=>$n->value,iterator_to_array($detail->query('//*[@data-piece="head-line"]//@data-domains-tone'))),'Detail state keeps its customer tone: '.$state);
    same(1,$detail->query('//*[@data-piece="head"]/*[@data-piece="head-line"]//*[@data-domains-tone]')->length,'The state sits in the detail head: '.$state);
    $card=storefrontDom(view('domains::service',['domain'=>$owned])->render());
    same([[$expected],$reason===null?[]:[$reason]],[$pills($card),$reasons($card)],'Service card shows the same pill and reason: '.$state);
}
$list=storefrontDom(view('domains::index',['domains'=>new LengthAwarePaginator($rows,count($rows),25)])->render());
same(count($rows),count($pills($list)),'The full list shows one pill per domain and nothing else');
same(array_column($cases,3),$pills($list),'List pills follow the resolved states');
// Detail pages carry no other pills: management sections, notices and check details are plain text.
$m=['nameservers'=>['label'=>'Nameservers','url'=>'/n','actions'=>['nameservers'=>'/n'],'max'=>12,'defaults'=>true,'current'=>['ns1.example.net','ns2.example.net'],'registrar'=>'Enom','defaultServers'=>['ns1.site.example.org','ns2.site.example.org']],
    'lock'=>['label'=>'Transfer lock','url'=>'/l','actions'=>['lock'=>'/l']],'privacy'=>['label'=>'Domain privacy','url'=>'/p','free'=>true,'price'=>null,'actions'=>['privacy_enable'=>'/e','privacy_disable'=>'/x']]];
$full=new OwnedDomain('full.example','Active','ok',$later,'On','$14.00',['show'=>'/domains/1'],[],['contacts'=>false,'required'=>false],[],$m,true,autoRenew:['url'=>'/a','enabled'=>true]);
same(['Active'],$pills(storefrontDom(view('domains::show',['domain'=>$full,'summary'=>$summary('full.example',['domain.active','adopt.provenance','adopt.renewal_off'])])->render())),'Managed detail with every section still shows one pill');
$nativeSuccessful=true;
