<?php
// Full native observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/observation-records.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,ObservationWork,OwnerRecords,ReadDeferred};
require_once __DIR__.'/Support/staff-panel.php';
\Paymenter\Extensions\Servers\Domains\Services\WatchDnsSchema::install();
// The combined child-host limit is a specific hold; reducing its sources makes retry work.
[$d,$driver]=$holding('candidate-limit-placeholder.com');
// The registration fixture rewinds the clock; isolate this case from earlier quota windows.
DB::table('domains_read_budgets')->where('server_id',$d->server_id)->delete();
$profiles=[];
for ($i=0;$i<26;$i++) {
    $profiles[]=DB::table('domains_watch_profiles')->insertGetId(['name'=>'Limit fixture '.$i,
        'nameservers'=>json_encode(['ns'.($i*2+1).'.'.$d->domain,'ns'.($i*2+2).'.'.$d->domain]),
        'website_endpoints'=>'{}','email_endpoints'=>'{}','edited_by'=>$user->id,'reason'=>'synthetic']);
}
$limitActor=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,'admin.domains.manage']);
$limitJob=new \Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords($limitActor->id,$d->id,$d->authority_generation,$d->account_fingerprint);
$recordIo=count($state->calls);$clock->instant=$clock->now()->modify('+61 seconds');$limitJob->handle($observer);
same($recordIo,count($state->calls),'Candidate limit makes no record-discovery calls');
expect(isset($d->fresh()->observation['facts']),'Candidate limit still saves core facts');
same('child_host_limit',$d->fresh()->observation['record_hold']['reason'],'Candidate limit has its own recoverable cause');
$notice=App\Models\Notification::where('user_id',$limitActor->id)->latest('id')->firstOrFail();
same("Couldn't read all records",$notice->title,'Candidate limit never reports Domain synced');
same("Missing: more than 50 child hosts to check. Ask your site admin to remove unused entries from this domain's saved discovery data, registrar nameservers and DNS templates so at most 50 child hosts remain, then use Sync now.",$notice->body,'Candidate-limit inbox gives the real limit and required source cleanup');
DB::table('domains_watch_profiles')->where('id',array_pop($profiles))->delete();
$clock->instant=$clock->now()->modify('+61 seconds');$limitJob->handle($observer);
expect(!ObservationRecords::held($d->fresh()),'Reducing candidates to50 allows successful discovery ('.($d->fresh()->observation['record_hold']['reason']??'none').'/'.($d->fresh()->observation['record_hold']['code']??'none').')');
same(50,count($d->fresh()->observation['records']['families']['glue']['candidates']),'Discovery retains every candidate at its limit');
same('Domain synced',App\Models\Notification::where('user_id',$limitActor->id)->latest('id')->value('title'),'Retry after reducing sources honestly reports success');

$nativeSuccessful=true;echo "Combined candidate limit, exact staff inbox and recovery at50 passed.\n";
