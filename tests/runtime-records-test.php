<?php
// Native producer gates and customer order/copy pairs. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/runtime-records.php';
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,RuntimeWork,ObservationWork,ObservationRecords,OwnerRecords,ManagementPayload,ReadDeferred,DomainObservation};
Queue::fake();$finance=[$original->fresh()->getAttributes(),$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->confirmed_revision,$d->renewal_policy];
$registerRecords();app(ObservationWork::class)->enroll($d->server_id);$dueRecords();$runtimePorts->initialManagedRecordObservations();
$job=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();
// Current holds suppress automatic retries, and the note states the manual-resume prerequisite.
$state->fault='GetHosts';$dueRecords();$runtimePorts->initialManagedRecordObservations();expect(ObservationRecords::held($d->fresh()),'Failed native discovery creates current hold');
$failedJob=DB::table('domains_observation_work')->where('domain_id',$d->id);
if(in_array((clone $failedJob)->value('safe_code'),\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::TRANSIENT,true)){
    for($try=2;$try<=5;$try++){
        $clock->instant=$clock->now()->setTimestamp((int)(clone $failedJob)->value('due_at'));
        DB::table('domains_observation_queues')->where('server_id',$d->server_id)->update(['turn'=>-1]);
        $runtimePorts->initialManagedRecordObservations();same($try,(int)(clone $failedJob)->value('tries'),'Scheduled family retry count is bounded and durable');
    }
}
expect(\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::stopped($d->fresh()),'The actual failed scheduler has stopped before manual recovery');
$html=$page();/* slop-sweep: the page notice says the held read once; rows show a calm Missing alone. */expect(str_contains($html,e(Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD))&&str_contains($html,'domains-missing domains-missing--calm')&&!str_contains($html,Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::TEAM_CHECK),'Held copy describes actual manual recovery once');expect(!str_contains($html,'Fills in by itself within a day'),'Held records never promise an automatic record retry');
$io=count($state->calls);DB::table('domains_observation_queues')->where('server_id',$d->server_id)->update(['turn'=>-1]);$clock->instant=$clock->now()->modify('+1 day');$runtimePorts->initialManagedRecordObservations();same($io,count($state->calls),'Held producer never automatically retries');
$state->fault='';app(DomainObservation::class)->run($d->id,readRecords:true);\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::completedManually($d->fresh());expect(!ObservationRecords::held($d->fresh()),'Explicit resume recovers native producer');
$p=$d->fresh()->observation;unset($p['records']);$d->fresh()->update(['observation'=>$p]);$html=$page();same(['Checking',''],[trim(storefrontDom($html)->query('//li[@id="dns"]//span[@data-record-family="dns"]')->item(0)?->textContent??''),trim(storefrontDom($html)->query('//li[@id="dns"]/span[@class="domains-row__v"]')->item(0)->textContent)],'Unheld unknown record says Checking once, beside the label');expect(!str_contains($html,'our team resumes'),'Unheld note has no false manual hold');
$visible=strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',$html));
foreach(['enom','namesilo']as$name){expect(!str_contains(strtolower($visible),$name),'Customer copy stays registrar-neutral');}
// A failed ordinary read while processing is off stops scheduled work without a record hold.
$bindings->setProcessing($d->server_id,false);$observation->fault='info_missing';$dueRecords();$runtimePorts->initialManagedRecordObservations();
same('read_no_evidence',DB::table('domains_observation_work')->where('id',$job->id)->value('safe_code'),'Paused ordinary read failure stops its job');
expect(!ObservationRecords::held($d->fresh()),'Stopped ordinary read has no fabricated record hold');
$bindings->setProcessing($d->server_id,true,static fn()=>null);$html=$page();/* slop-sweep: said once in the page notice. */expect(str_contains($html,e(Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD))&&str_contains($html,'domains-missing domains-missing--calm')&&!str_contains($html,Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::TEAM_CHECK),'Stopped scheduled reads require manual-recovery copy once');expect(!str_contains($html,'Fills in by itself within a day'),'Stopped scheduled reads never promise automatic retries');
$io=count($observation->calls);$clock->instant=$clock->now()->modify('+1 day');$runtimePorts->initialManagedRecordObservations();same($io,count($observation->calls),'Stopped job never retries automatically after re-enabling');
$observation->fault='';app(ObservationWork::class)->resume($job->id,'staff_scheduled_resume');$runtimePorts->initialManagedRecordObservations();expect(!ObservationRecords::stopped($d->fresh()),'Explicit resume clears stopped scheduled work');
$p=$d->fresh()->observation;unset($p['records']);$d->fresh()->update(['observation'=>$p]);
same($finance,[$original->fresh()->getAttributes(),$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->fresh()->confirmed_revision,$d->fresh()->renewal_policy],'Record producer never changes financial authority');
echo "Native held record reads and explicit manual recovery passed.\n";$nativeSuccessful=true;
