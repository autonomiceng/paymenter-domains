<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsQuery,DnsAnswer,PublicSignalsTarget};
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicObservation,ManagedPublicConfig,WatchName,WatchSource};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedPublicSignals,PublicSignalsProjection,ManagedPublicWorkSchema};
extract(managedFixture(true));$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now;
// Seed an unused shared window when absent, then align it with the fixture clock.
\Paymenter\Extensions\Servers\Domains\Services\PublicDnsQuota::seed($now);
$pool=DB::table(\Paymenter\Extensions\Servers\Domains\Services\PublicDnsQuota::TABLE)->where('id',1);
same(0,(int)(clone $pool)->value('used'),'The observer starts without public quota consumption');
same([],json_decode((clone $pool)->value('active'),true),'The observer starts without active public checks');
$pool->update(['window_at'=>$now]);
// This confirmation/isolation scenario starts with known delegation; first-read imports have their own table.
$d->update(['observation'=>\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::recordNameservers($d,
    \Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence::observed($profile->profile()->nameservers,'enom_getdns',new DateTimeImmutable('@'.$now)))]);
$before=DB::table(ManagedPublicWorkSchema::TABLE)->orderBy('id')->get();$money=scenarioMoney();$authority=$d->fresh()->getRawOriginal();$count=WatchName::count();$calls=count($wire->calls);
$off=new ManagedPublicSignals($fake);expect(!$off->enqueue($id,$uid,$now),'Activation remains explicit');
same('awaiting_acceptance',DB::table(ManagedPublicWorkSchema::TABLE)->value('safe_code'),'Old A3 enrollment stays dormant by default');
expect($signals->enqueue($id,$uid),'DNR holding enrolls');same($count,WatchName::count(),'Managed enrollment never creates a watch');
same($before->pluck('id')->all(),DB::table(ManagedPublicWorkSchema::TABLE)->orderBy('id')->pluck('id')->all(),'Original A3 work IDs consumed in place');
same('linked',$watch->fresh()->state,'Predecessor tombstone retained');same('managed_link',WatchSource::where('managed_domain_id',$id)->sole()->state,'Source relink retained');
same(true,ManagedPublicConfig::find($id)->notice_preferences['dns'],'DNS preference independent from DNR');
$bindings->setProcessing($tld->server_id,false);
$fake->answers['example.com|NS']=function(){same(0,DB::transactionLevel(),'DNS outside transactions');return managedNs();};
expect($signals->observe($id,$uid,'delegation'),'Registrar processing gate does not gate public DNS');
same(false,ManagedPublicObservation::sole()->ns_confirmed,'One sample never confirms an NS episode');
$w=DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','delegation')->first();same($now+900,(int)$w->due_at,'Second sample waits at least fifteen minutes');
$time->now=$now+900;expect($signals->observe($id,$uid,'delegation'),'Second matching sample accepted');same(true,ManagedPublicObservation::orderByDesc('id')->first()->ns_confirmed,'Two spaced samples confirm');
foreach(['example.com','www.example.com']as$hostName){foreach(['CNAME','A','AAAA']as$type){$q=new DnsQuery($hostName,$type);$fake->answers[$q->key()]=DnsAnswer::negative($q);}}
$q=new DnsQuery('example.com','MX');$fake->answers[$q->key()]=DnsAnswer::negative($q);
expect($signals->observe($id,$uid,'points'),'Managed linked primary hostnames and apex use shared classifier');
expect(in_array('www.example.com|A',$fake->calls,true) && in_array('example.com|A',$fake->calls,true),'Apex and hosting primary both queried');
$target=PublicSignalsTarget::managed($id,$uid,$d->account_fingerprint,$d->authority_generation,$d->confirmed_revision);
$view=(new PublicSignalsProjection())->read($target,$time->now+1);same('platform',$view['dns']['value'],'Managed badges read managed facts');expect(!$view['dns']['stale'],'Managed facts current');
same(null,(new PublicSignalsProjection())->read(PublicSignalsTarget::watch($watch->id,$uid,$watch->fresh()->revision),$time->now),'Linked watch cannot supply managed badges');
$pointsDue=DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','points')->value('due_at');managedDue($id,$time->now);
expect($signals->observe($id,$uid,'delegation'),'Unchanged confirmed delegation observed');
same($pointsDue,DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','points')->value('due_at'),'Routine NS checks preserve daily points cadence');
managedDue($id,$time->now);$fake->answers['example.com|NS']=fn()=>managedNs('ns.external.test',3600);
expect($signals->observe($id,$uid,'delegation'),'Mismatch captured as unconfirmed');same(false,ManagedPublicObservation::orderByDesc('id')->first()->ns_confirmed,'Old confirmation does not authorize a changed NS set');
$time->now+=3600;expect($signals->observe($id,$uid,'delegation'),'TTL-separated differing set confirmed');same(true,ManagedPublicObservation::orderByDesc('id')->first()->ns_confirmed,'Stable mismatch confirmed');
$time->now+=43201;managedDue($id,$time->now);
expect($signals->observe($id,$uid,'delegation'),'Fresh read after prior evidence expired');
same(false,ManagedPublicObservation::orderByDesc('id')->first()->ns_confirmed,'Expired confirmation requires a new second sample');
same($authority,$d->fresh()->getRawOriginal(),'Public DNS does not change holding authority, expiry, permissions or observation');same($money,scenarioMoney(),'No financial mutation');same($calls,count($wire->calls),'No registrar reads');
// Glue drift shares this public schedule and resolver; it never contacts the registrar.
// The saved registrar inventory and public DNS checks use the same synthetic instant.
$clock->time=new DateTimeImmutable('@'.$time->now);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::save($d->fresh(),['glue'=>['records'=>['ns1.example.com'=>'8.8.8.8']]],new DateTimeImmutable('@'.$time->now));
$glueAuthority=$d->fresh()->getRawOriginal();$q=new DnsQuery('ns1.example.com','A');
$fake->answers[$q->key()]=DnsAnswer::rrset($q,[['host'=>$q->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>'1.1.1.1']]);
managedDue($id,$time->now);expect($signals->observe($id,$uid,'delegation'),'Scheduled public check includes saved glue');
expect(count(\Paymenter\Extensions\Servers\Domains\Services\GlueDrift::warnings($d->fresh(),$time->now))===1,'Scheduled mismatch produces an advisory');
$fake->answers[$q->key()]=DnsAnswer::unknown($q);
managedDue($id,$time->now);expect($signals->observe($id,$uid,'delegation'),'A later failed DNS read is retained as unknown');
expect(count(\Paymenter\Extensions\Servers\Domains\Services\GlueDrift::warnings($d->fresh(),$time->now))===1,'Unknown DNS retains the unexpired drift advisory');
same([],\Paymenter\Extensions\Servers\Domains\Services\GlueDrift::warnings($d->fresh(),$time->now+43201),'An old drift advisory still expires');
$fake->answers[$q->key()]=DnsAnswer::rrset($q,[['host'=>$q->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>'8.8.8.8']]);
managedDue($id,$time->now);expect($signals->observe($id,$uid,'delegation'),'Scheduled public check samples matching glue');
same([],\Paymenter\Extensions\Servers\Domains\Services\GlueDrift::warnings($d->fresh(),$time->now),'Later matching public DNS clears the advisory');
same($glueAuthority,$d->fresh()->getRawOriginal(),'Glue diagnostics leave the holding and its in-flight authority stamp unchanged');
same($calls,count($wire->calls),'Glue diagnostics never contact the registrar');same($money,scenarioMoney(),'Glue diagnostics never change financial state');
DB::table('domains_domains')->where('id',$id)->update(['status'=>'expired']);expect(!$signals->enqueue($id,$uid,$time->now),'Expired work retires');
expect(DB::table(ManagedPublicWorkSchema::TABLE)->whereNull('retired_at')->doesntExist(),'All expired work retired');
expect((new PublicSignalsProjection())->read($target,$time->now)['dns']['stale'],'Retained facts visibly stale after retirement');
$nativeSuccessful=true;echo "Managed DNR enrollment, explicit activation, shared classifier, NS confirmation and authority isolation passed.\n";
