<?php
// Native producer gates and customer order/copy pairs. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/runtime-records-ready.php';
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,RuntimeWork,ObservationWork,ObservationRecords,OwnerRecords,ManagementPayload,ReadDeferred,DomainObservation};
// Current holds suppress automatic retries, and the note states the manual-resume prerequisite.
$state->fault='GetHosts';$dueRecords();$runtimePorts->initialManagedRecordObservations();expect(ObservationRecords::held($d->fresh()),'Failed native discovery creates current hold');
$html=$page();same(1,substr_count($html,e(Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD)),'Held notice describes manual recovery once');expect(!str_contains($html,Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::TEAM_CHECK),'Record rows do not repeat the held notice');expect(!str_contains($html,'Fills in by itself within a day'),'Held records never promise an automatic record retry');
$io=count($state->calls);$clock->instant=$clock->now()->modify('+1 day');$runtimePorts->initialManagedRecordObservations();same($io,count($state->calls),'Held producer never automatically retries');
$state->fault='';$resumed=app(DomainObservation::class)->run($d->id,readRecords:true);expect(!ObservationRecords::held($d->fresh()),'Explicit resume recovers native producer: '.json_encode(['result'=>$resumed,'hold'=>$d->fresh()->observation['record_hold']??null,'records'=>ObservationRecords::current($d->fresh())]));
$p=$d->fresh()->observation;unset($p['records']);$d->fresh()->update(['observation'=>$p]);$html=$page();same(['Checking',''],[trim(storefrontDom($html)->query('//li[@id="dns"]//span[@data-record-family="dns"]')->item(0)?->textContent??''),trim(storefrontDom($html)->query('//li[@id="dns"]/span[@class="domains-row__v"]')->item(0)->textContent)],'Unheld unknown record says Checking once, beside the label');expect(!str_contains($html,'our team resumes'),'Unheld note has no false manual hold');
$visible=strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',$html));
foreach(['enom','namesilo']as$name){expect(!str_contains(strtolower($visible),$name),'Customer copy stays registrar-neutral');}
// A failed ordinary read while processing is off stops scheduled work without a record hold.
$bindings->setProcessing($d->server_id,false);$observation->fault='info_missing';$dueRecords();$runtimePorts->initialManagedRecordObservations();
same('read_no_evidence',DB::table('domains_observation_work')->where('id',$job->id)->value('safe_code'),'Paused ordinary read failure stops its job');
expect(!ObservationRecords::held($d->fresh()),'Stopped ordinary read has no fabricated record hold');
$bindings->setProcessing($d->server_id,true,static fn()=>null);$html=$page();/* slop-sweep: said once in the page notice. */expect(str_contains($html,e(Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD))&&!str_contains($html,Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::TEAM_CHECK),'Stopped scheduled reads describe manual recovery without repeated team guidance');expect(!str_contains($html,'Fills in by itself within a day'),'Stopped scheduled reads never promise automatic retries');
$io=count($observation->calls);$clock->instant=$clock->now()->modify('+1 day');$runtimePorts->initialManagedRecordObservations();same($io,count($observation->calls),'Stopped job never retries automatically after re-enabling');
$observation->fault='';app(ObservationWork::class)->resume($job->id,'staff_scheduled_resume');$runtimePorts->initialManagedRecordObservations();expect(!ObservationRecords::stopped($d->fresh()),'Explicit resume clears stopped scheduled work');
$p=$d->fresh()->observation;unset($p['records']);$d->fresh()->update(['observation'=>$p]);
// Acceptance can disappear without changing locked rows; it is checked again before publication.
$state->during=static function()use(&$compositionReady):void{$compositionReady=false;};$dueRecords();
try{app(DomainObservation::class)->run($d->id);expect(false,'Mid-flight acceptance loss must defer publication');}catch(ReadDeferred){}
same(null,ObservationRecords::current($d->fresh()),'No inventory committed after acceptance loss');$compositionReady=true;
// Pausing mid-flight discards record publication; independent snapshots continue on later paused ticks.
$state->during=fn()=>$bindings->setProcessing($d->server_id,false);$dueRecords();
try{app(DomainObservation::class)->run($d->id);expect(false,'Mid-flight pause must defer publication');}catch(ReadDeferred){}
same(null,ObservationRecords::current($d->fresh()),'No inventory committed after processing pause');$state->calls=[];$observation->years=2;$dueRecords();$runtimePorts->initialManagedRecordObservations();
same([],$state->calls,'Paused refresh never calls record transport');same('2028-10-18',$d->fresh()->observation['facts']['expiry'],'Paused records do not freeze independent expiry observations');
same($finance,[$original->fresh()->getAttributes(),$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->fresh()->confirmed_revision,$d->fresh()->renewal_policy],'Record producer never changes financial authority');
echo "Native record manual recovery, stopped work and competing authority gates passed.\n";$nativeSuccessful=true;
