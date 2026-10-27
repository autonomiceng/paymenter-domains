<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsQuery,DnsAnswer};
use Paymenter\Extensions\Servers\Domains\Models\ManagedPublicObservation;
use Paymenter\Extensions\Servers\Domains\Services\ManagedPublicWorkSchema;
extract(managedFixture());$id=(int)$d->id;$uid=(int)$owner->id;$start=$time->now;
foreach(['example.com','www.example.com']as$hostName){foreach(['CNAME','A','AAAA']as$type){$q=new DnsQuery($hostName,$type);$fake->answers[$q->key()]=DnsAnswer::negative($q);}}
$q=new DnsQuery('example.com','MX');$fake->answers[$q->key()]=DnsAnswer::negative($q);
expect($signals->observe($id,$uid,'points'),'Both routing axes known external');
$fake->answers['example.com|NS']=fn()=>managedNs('ns.platform.test',21600);
expect($signals->observe($id,$uid,'delegation'),'Hourly DNS-only candidate captured');
$first=ManagedPublicObservation::where('check_kind','delegation')->sole();
same($start+7200,$first->valid_until,'Hourly badge freshness remains two hours');
same($start+21600,(int)DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','delegation')->value('due_at'),'Candidate waits the full observed TTL');
$time->now+=21600;
expect($signals->observe($id,$uid,'delegation'),'TTL-separated sample accepted after candidate badge expired');
same(true,ManagedPublicObservation::where('check_kind','delegation')->orderByDesc('id')->first()->ns_confirmed,'Long TTL can confirm hourly DNS-only holding');
same($time->now+3600,(int)DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','delegation')->value('due_at'),'Confirmed DNS-only holding resumes hourly cadence');
managedDue($id,$time->now+1);$late=$signals->begin($id,$uid,'delegation',$time->now+1);expect(!$signals->finish($late,new Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation',dns:'platform'),$time->now+46),'Late managed NS refresh discarded');
managedDue($id,$time->now+47);$wait=$signals->begin($id,$uid,'delegation',$time->now+47);$signals->defer($wait,$time->now+47);expect((new Paymenter\Extensions\Servers\Domains\Services\PublicSignalsProjection())->read(Paymenter\Extensions\Servers\Domains\Dns\PublicSignalsTarget::managed($id,$uid,$d->account_fingerprint,$d->authority_generation,$d->confirmed_revision),$time->now+48)['dns']['stale'],'Quota cannot revive deadline-failed managed evidence');
$time->now+=7201;managedDue($id,$time->now);
expect($signals->observe($id,$uid,'delegation'),'Expired confirmation replaced by a fresh candidate');
same(false,ManagedPublicObservation::where('check_kind','delegation')->orderByDesc('id')->first()->ns_confirmed,'Expired confirmed sample never carries forward');
$nativeSuccessful=true;echo "Long-TTL two-sample confirmation and independent badge freshness passed.\n";
