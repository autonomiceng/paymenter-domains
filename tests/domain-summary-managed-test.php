<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;
use Paymenter\Extensions\Servers\Domains\Models\Adoption;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
extract(adoptionCommitFixture());
$a=Adoption::findOrFail($reader->preview($target,$context)->previewId);
$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
require dirname(__DIR__).'/Domains/routes/owned.php';require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
require_once __DIR__.'/Support/storefront.php';storefrontViews();
$r=new DomainSummaries();$now=$clock->now()->getTimestamp();
$storeNameservers=static function(NameserverEvidence $fact)use($d):void{$d->update(['observation'=>['nameservers'=>$fact->toArray()+[
    'name'=>$d->domain,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint]]]);};
$registrarNameservers=NameserverEvidence::observed(['ns1.registration.example','ns2.registration.example'],'namesilo_domain_info',$clock->now());
$storeNameservers($registrarNameservers);
$staleFact=$registrarNameservers->toArray()+['name'=>$d->domain,'generation'=>$d->authority_generation-1,'account_fingerprint'=>$d->account_fingerprint];
$d->update(['observation'=>['nameservers'=>$staleFact]]);$staleSummary=$r->one($owner,$d->id,$now);
same(null,$staleSummary->nameservers,'Prior-generation registrar nameservers are hidden');
$storeNameservers($registrarNameservers);
$initial=$r->one($owner,$d->id,$now);$initialHtml=view('domains::partials.summary',['summary'=>$initial,'summaryOnManaged'=>true])->render();
same(['ns1.registration.example','ns2.registration.example'],$initial->nameservers['nameservers']??null,'Current registration nameservers appear before the first DNS check');
expect(str_contains($initialHtml,'<dt>Nameservers</dt><dd><span class="domains-wrap">ns1.registration.example, ns2.registration.example</span> <span class="domains-small">Checked ')&&!str_contains($initialHtml,'domains-missing'),'Owner sees current registration nameservers before a DNS check');
$requested=NameserverEvidence::requested(['ns1.registration.example','ns2.registration.example'],'registration_request',$clock->now());$storeNameservers($requested);
$requestedSummary=$r->one($owner,$d->id,$now);$requestedHtml=view('domains::partials.summary',['summary'=>$requestedSummary,'summaryOnManaged'=>true])->render();
same('requested',$requestedSummary->nameservers['state']??null,'Requested values remain unconfirmed in the owner summary');
expect(str_contains($requestedHtml,'<span class="domains-wrap">ns1.registration.example, ns2.registration.example</span> <span class="domains-small">Requested ')&&!str_contains($requestedHtml,'Checked '),'Owner labels submitted nameservers as requested');
$storeNameservers($registrarNameservers);
same(null,$r->one($staff,$d->id,$now),'Foreign ledger locator is refused');
// The batched list preserves acquisition states without per-row capability reads.
foreach(['transfer_pending'=>['domain.transfer','>Pending transfer<','info'],'cancelled'=>['domain.cancelled','>Cancelled<','muted']] as $status=>[$key,$copy,$tone]){DB::table('domains_domains')->where('id',$d->id)->update(['status'=>$status]);$entry=$r->one($owner,$d->id,$now+62);$list=view('domains::index',['domains'=>new Illuminate\Pagination\LengthAwarePaginator([$entry],1,25)])->render();expect(in_array($key,$entry->stateKeys,true)&&str_contains($list,$copy),'List preserves '.$status);same($tone,\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($entry)['tone'],'D3 managed state tone '.$status);expect(str_contains($list,'domains-tone-'.$tone),'D3 managed list tone '.$status);}
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'registration_pending']);
$acq=Paymenter\Extensions\Servers\Domains\Models\Operation::create(['domain_id'=>$d->id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>'summary-acquisition','action'=>'register','currency'=>'USD','amount'=>'14.00','state'=>'awaiting_payment']);
foreach(['awaiting_payment'=>['domain.awaiting_payment','>Awaiting payment<','info'],'held'=>['domain.processing','>Under review<','info'],'uncertain'=>['domain.processing','>Pending registration<','info']] as $state=>[$key,$copy,$tone]){DB::table('domains_operations')->where('id',$acq->id)->update(['state'=>$state]);$entry=$r->one($owner,$d->id,$now+62);$list=view('domains::index',['domains'=>new Illuminate\Pagination\LengthAwarePaginator([$entry],1,25)])->render();expect(in_array($key,$entry->stateKeys,true)&&str_contains($list,$copy),'List preserves '.$state);same($tone,\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($entry)['tone'],'D3 acquisition tone '.$state);expect(str_contains($list,'domains-tone-'.$tone),'D3 acquisition list tone '.$state);}
// A cancelled service never offers to take payment, on the list as on the page.
$serviceStatus=DB::table('services')->where('id',$d->service_id)->value('status');DB::table('domains_operations')->where('id',$acq->id)->update(['state'=>'awaiting_payment']);DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']);
// An order not yet registered has no expiry, so the row reads the cancelled order, not a date.
$expiresAt=DB::table('domains_domains')->where('id',$d->id)->value('provider_expires_at');DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>null]);
$entry=$r->one($owner,$d->id,$now+62);same(['cancelled','cancelled','Order cancelled',null],[$entry->order['state']??null,$entry->statusState,$entry->statusReason,$entry->expiry],'List closes an unpaid order on a cancelled service as a plain cancel');
$row=Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::expires($entry);same([false,null,null],[$row['missing'],$row['cause']??null,$row['tone']??null],'An unpaid cancel has no Missing mark and no Contact support');
DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>$expiresAt]);
DB::table('services')->where('id',$d->service_id)->update(['status'=>$serviceStatus]);
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'active']);
foreach(['expired','grace','redemption'] as $status){DB::table('domains_operations')->where('id',$acq->id)->update(['state'=>'succeeded']);DB::table('domains_domains')->where('id',$d->id)->update(['status'=>$status]);$entry=$r->one($owner,$d->id,$now+62);$key=$status==='redemption'?'domain.redemption':'domain.expired';expect(in_array($key,$entry->stateKeys,true),'Known lifecycle expiry remains distinct from registry boundary');same('bad',\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($entry)['tone'],'D3 known expired/grace/redemption is bad');}
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'active']);
// Native and ledger owners are both required, even for a signed-in staff user.
DB::table('services')->where('id',$d->service_id)->update(['user_id'=>$staff->id]);same(null,$r->one($owner,$d->id,$now+22),'Moved native owner refuses ledger owner');same(null,$r->one($staff,$d->id,$now+22),'Moved native owner cannot claim foreign ledger');
$nativeSuccessful=true;echo "Managed summary generation, adoption, expiry boundary, stale retention and native ownership passed.\n";
