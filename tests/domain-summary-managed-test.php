<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,ManagedPublicWorkSchema};
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsQuery,DnsReading};
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
$f=managedFixture();extract($f);\Paymenter\Extensions\Servers\Domains\Services\WatchScheduleSchema::install();\Paymenter\Extensions\Servers\Domains\Services\WatchNoticeSchema::install();require dirname(__DIR__).'/Domains/routes/owned.php';require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
require_once __DIR__.'/Support/storefront.php';storefrontViews();
$r=new DomainSummaries();$now=$time->now;expect($signals->enqueue($d->id,$owner->id,$now),'Managed enrollment');
$storeNameservers=static function(NameserverEvidence $fact)use($d):void{$d->update(['observation'=>['nameservers'=>$fact->toArray()+[
    'name'=>$d->domain,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint]]]);};
$registrarNameservers=NameserverEvidence::observed(['ns1.registration.example','ns2.registration.example'],'namesilo_domain_info',$clock->now());
$storeNameservers($registrarNameservers);
$staleFact=$registrarNameservers->toArray()+['name'=>$d->domain,'generation'=>$d->authority_generation-1,'account_fingerprint'=>$d->account_fingerprint];
$d->update(['observation'=>['nameservers'=>$staleFact]]);$staleSummary=$r->one($owner,'managed',$d->id,$now);
same(null,$staleSummary->nameservers,'Prior-generation registrar nameservers are hidden');
$storeNameservers($registrarNameservers);
$initial=$r->one($owner,'managed',$d->id,$now);$initialHtml=view('domains::partials.summary',['summary'=>$initial,'summaryOnManaged'=>true])->render();
same(['ns1.registration.example','ns2.registration.example'],$initial->nameservers['nameservers']??null,'Current registration nameservers appear before the first DNS check');
expect(str_contains($initialHtml,'<dt>Nameservers</dt><dd><span class="domains-wrap">ns1.registration.example, ns2.registration.example</span> <span class="domains-small">Checked ')&&!str_contains($initialHtml,'domains-missing'),'Owner sees current registration nameservers before a DNS check');
$requested=NameserverEvidence::requested(['ns1.registration.example','ns2.registration.example'],'registration_request',$clock->now());$storeNameservers($requested);
$requestedSummary=$r->one($owner,'managed',$d->id,$now);$requestedHtml=view('domains::partials.summary',['summary'=>$requestedSummary,'summaryOnManaged'=>true])->render();
same('requested',$requestedSummary->nameservers['state']??null,'Requested values remain unconfirmed in the owner summary');
expect(str_contains($requestedHtml,'<span class="domains-wrap">ns1.registration.example, ns2.registration.example</span> <span class="domains-small">Requested ')&&!str_contains($requestedHtml,'Checked '),'Owner labels submitted nameservers as requested');
$storeNameservers($registrarNameservers);
foreach(['delegation','points'] as $kind){managedDue($d->id,$now,$kind);$t=$signals->begin($d->id,$owner->id,$kind,$now);expect($t!==null,'Managed ticket');expect($signals->finish($t,$kind==='delegation'?new DnsReading($kind,'platform'):new DnsReading($kind,website:false,email:false),$now+1),'Managed sample');}
$s=$r->one($owner,'managed',$d->id,$now+2);expect(in_array('domain.points.platform_empty',$s->dnsKeys,true),'Current generation complete DNS only');same('domain.registration.platform',$s->registrationKey,'Adopted managed registration');expect(in_array('adopt.provenance',$s->stateKeys,true),'Adopted activity');expect($s->urls['preferences']!==null,'Managed preferences remain available with hosting links');
$markup=view('domains::partials.summary',['summary'=>$s])->render();expect(str_contains($markup,'Domain check notifications')&&str_contains($markup,e($s->urls['preferences'])),'Managed detail exposes notification preferences');
expect(isset($r->services($owner,[$host->id],$now+2)[$host->id]),'Hosting row receives managed summary');same(null,$r->one($staff,'managed',$d->id,$now+2),'Foreign managed locator');
// A retired earlier generation must not hide current facts or supply next-attempt dates.
$old=(array)DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$d->id)->where('kind','points')->first();unset($old['id']);$old['generation']=$d->authority_generation-1;$old['retired_at']=$now-20;$old['due_at']=$now-100;DB::table(ManagedPublicWorkSchema::TABLE)->insert($old);
$s=$r->one($owner,'managed',$d->id,$now+2);expect(in_array('domain.points.platform_empty',$s->dnsKeys,true),'Old work does not override current generation');
$boundary=$d->provider_expires_at->startOfDay()->getTimestamp();$s=$r->one($owner,'managed',$d->id,$boundary);expect(!in_array('domain.points.platform_empty',$s->dnsKeys,true),'Expired structural authority cannot claim DNS only');expect(in_array('watch.expired',$s->stateKeys,true),'Expiry day is explicit');same(['label'=>'Expired','tone'=>'bad','reason'=>null],\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($s),'Expiry day reads Expired on every page');
$dnsQuery=new DnsQuery($d->domain,'NS');$answer=DnsAnswer::rrset($dnsQuery,[
    ['host'=>$d->domain,'class'=>'IN','type'=>'NS','target'=>'ns1.public.example','ttl'=>300],
    ['host'=>$d->domain,'class'=>'IN','type'=>'NS','target'=>'ns2.public.example','ttl'=>300],
]);
managedDue($d->id,$now+10,'delegation');$ticket=$signals->begin($d->id,$owner->id,'delegation',$now+10);expect($ticket!==null,'Later delegation check starts');
expect($signals->finish($ticket,new DnsReading('delegation','external',queries:[$answer]),$now+11),'Later delegation check completes');
$checked=$r->one($owner,'managed',$d->id,$now+12);$checkedHtml=view('domains::partials.summary',['summary'=>$checked,'summaryOnManaged'=>true])->render();
same(['ns1.public.example','ns2.public.example'],$checked->nameservers['nameservers']??null,'Later public DNS check overrides registrar nameservers');
same('public_dns_check',$checked->nameservers['source']??null,'Owner summary labels public DNS source');
expect(str_contains($checkedHtml,'<span class="domains-wrap">ns1.public.example, ns2.public.example</span> <span class="domains-small">Checked ')&&!str_contains($checkedHtml,'ns1.registration.example'),'Owner page displays the later nameserver check');
$delegationObservation=\Paymenter\Extensions\Servers\Domains\Models\ManagedPublicObservation::where('domain_id',$d->id)->where('check_kind','delegation')->orderByDesc('id')->firstOrFail();
$stalePublicSummary=$r->one($owner,'managed',$d->id,(int)$delegationObservation->valid_until);
same(['ns1.registration.example','ns2.registration.example'],$stalePublicSummary->nameservers['nameservers']??null,'Expired public DNS nameservers no longer override registrar facts');
managedDue($d->id,$now+20,'points');$t=$signals->begin($d->id,$owner->id,'points',$now+20);expect($t!==null,'Unknown ticket');$signals->finish($t,new DnsReading('points'),$now+21);
$s=$r->one($owner,'managed',$d->id,$now+22);same(false,$s->lastKnown['website']['value'],'Failed attempt retains last value');same($now,$s->lastKnown['website']['checkedAt'],'Failed attempt retains last timestamp');same(true,$s->lastKnown['website']['stale'],'Retained fact is stale');expect(!in_array('domain.points.platform_empty',$s->dnsKeys,true),'Unknown managed points have no empty label');
$history=view('domains::partials.summary',['summary'=>$s])->render();expect(!str_contains($history,'Last known')&&!str_contains($history,'pointer')&&str_contains($history,'<span class="domains-wrap">ns1.public.example, ns2.public.example</span>'),'Failed check shows only current facts, never retained ones');
// Suspended related hosting is omitted by the producer; delegation remains current and partial positives survive.
$host->update(['status'=>'suspended']);
foreach(['delegation','points'] as $kind){managedDue($d->id,$now+40,$kind);$t=$signals->begin($d->id,$owner->id,$kind,$now+40);expect($t!==null,'Omitted hosting ticket');$signals->finish($t,$kind==='delegation'?new DnsReading($kind,'platform'):new DnsReading($kind,website:true,email:null,partial:true),$now+41);}
$s=$r->one($owner,'managed',$d->id,$now+42);expect(in_array('domain.dns.platform',$s->dnsKeys,true),'Suspended hosting does not hide delegation');expect(in_array('domain.points.website',$s->dnsKeys,true),'Current partial positive retained');expect(in_array('domain.points.partial',$s->dnsKeys,true),'Partial points explicit');expect(in_array('watch.partial',$s->stateKeys,true),'Partial state is not out of date');expect(!in_array('watch.stale',$s->stateKeys,true),'Current partial is not stale');same([],$s->relatedServices,'Suspended hosting link omitted');
managedDue($d->id,$now+60,'points');$t=$signals->begin($d->id,$owner->id,'points',$now+60);$signals->finish($t,new DnsReading('points',website:false,email:false),$now+61);$s=$r->one($owner,'managed',$d->id,$now+62);expect(!in_array('domain.points.platform_empty',$s->dnsKeys,true),'Omitted hosting cannot claim DNS only from negative results');
// The batched list preserves acquisition states without per-row capability reads.
foreach(['transfer_pending'=>['domain.transfer','>Pending transfer<','info'],'cancelled'=>['domain.cancelled','>Cancelled<','muted']] as $status=>[$key,$copy,$tone]){DB::table('domains_domains')->where('id',$d->id)->update(['status'=>$status]);$entry=$r->one($owner,'managed',$d->id,$now+62);$list=view('domains::index',['domains'=>new Illuminate\Pagination\LengthAwarePaginator([$entry],1,25)])->render();expect(in_array($key,$entry->stateKeys,true)&&str_contains($list,$copy),'List preserves '.$status);same($tone,\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($entry)['tone'],'D3 managed state tone '.$status);expect(str_contains($list,'domains-tone-'.$tone),'D3 managed list tone '.$status);}
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'registration_pending']);
$acq=Paymenter\Extensions\Servers\Domains\Models\Operation::create(['domain_id'=>$d->id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>'summary-acquisition','action'=>'register','currency'=>'USD','amount'=>'14.00','state'=>'awaiting_payment']);
foreach(['awaiting_payment'=>['domain.awaiting_payment','>Action required<','warn'],'held'=>['domain.processing','>Pending registration<','info'],'uncertain'=>['domain.processing','>Pending registration<','info']] as $state=>[$key,$copy,$tone]){DB::table('domains_operations')->where('id',$acq->id)->update(['state'=>$state]);$entry=$r->one($owner,'managed',$d->id,$now+62);$list=view('domains::index',['domains'=>new Illuminate\Pagination\LengthAwarePaginator([$entry],1,25)])->render();expect(in_array($key,$entry->stateKeys,true)&&str_contains($list,$copy),'List preserves '.$state);same($tone,\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($entry)['tone'],'D3 acquisition tone '.$state);expect(str_contains($list,'domains-tone-'.$tone),'D3 acquisition list tone '.$state);}
// A cancelled service never offers to take payment, on the list as on the page.
$serviceStatus=DB::table('services')->where('id',$d->service_id)->value('status');DB::table('domains_operations')->where('id',$acq->id)->update(['state'=>'awaiting_payment']);DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']);
$entry=$r->one($owner,'managed',$d->id,$now+62);same(['cancelled','needs_review','Order cancelled'],[$entry->order['state']??null,$entry->statusState,$entry->statusReason],'List closes an order on a cancelled service');
DB::table('services')->where('id',$d->service_id)->update(['status'=>$serviceStatus]);
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'active']);
foreach(['expired','grace','redemption'] as $status){DB::table('domains_operations')->where('id',$acq->id)->update(['state'=>'succeeded']);DB::table('domains_domains')->where('id',$d->id)->update(['status'=>$status]);$entry=$r->one($owner,'managed',$d->id,$now+62);$key=$status==='redemption'?'domain.redemption':'domain.expired';expect(in_array($key,$entry->stateKeys,true),'Known lifecycle expiry remains distinct from registry boundary');same('bad',\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($entry)['tone'],'D3 known expired/grace/redemption is bad');}
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'active']);
// Native and ledger owners are both required, even for a signed-in staff user.
DB::table('services')->where('id',$d->service_id)->update(['user_id'=>$staff->id]);same(null,$r->one($owner,'managed',$d->id,$now+22),'Moved native owner refuses ledger owner');same(null,$r->one($staff,'managed',$d->id,$now+22),'Moved native owner cannot claim foreign ledger');
$nativeSuccessful=true;echo "Managed summary generation, adoption, expiry boundary, stale retention and native ownership passed.\n";
