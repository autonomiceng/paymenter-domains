<?php
// Native staff workflow boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Every holding below belongs to Enom; no second registrar enrollment is needed.
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/staff-operation-fixture.php';
// These assertions need native financial events, not PDF layout.
require __DIR__.'/Support/native-invoice-attachment.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Services\{ObservationRecords,ObservationWork,StaffOperationReview,StaffSyncReview};
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
// Recreate the original completed request through the same audited staff writer.
[$check]=$prepare(placeholderComFixtureName('staff-recheck-placeholder.com'));
$run($check,'recheck');
$preflight->cost='8.95';

// Proven completion with retained execution/seal closes every operation-scoped action.
expect(($check->fresh()->attempt['execution']??null)!==null,'Completed fixture retains a known send response');
$closed=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->assertDontSee('More')
    ->assertSee('Completed')->assertDontSee('has not confirmed');
$notice=Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($check->fresh());
same([null,'domain'],[$notice['action']??null,$notice['link']??null],'Completed request with details to fix offers no request action, only the domain');
$before=[$check->fresh()->getRawOriginal(),$check->domain->fresh()->getRawOriginal()];
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){
    // Resume sync sits on the domain page; every action is still refused below.
    if($action!=='resume_observation'){$closed->assertActionHidden($action);}
    commerceReject(fn()=>$run($check->fresh(),$action));
    same($before,[$check->fresh()->getRawOriginal(),$check->domain->fresh()->getRawOriginal()],'Direct completed action leaves operation and domain unchanged');
}
unset($closed);gc_collect_cycles();
// Domain recovery appears for stopped scheduled work, never on the completed request.
// Activation already queued this exact holding. Stop that row instead of inserting a duplicate.
$stopped=DB::table('domains_observation_work')->where(['server_id'=>$check->server_id,'account_fingerprint'=>$check->account_fingerprint,'domain_id'=>$check->domain_id,
    'generation'=>$check->authority_generation,'phase'=>hash('sha256',$check->operation_key)])->sole()->id;
DB::table('domains_observation_work')->where('id',$stopped)->update(['due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
$stoppedPage=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionVisible('resume_observation');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->assertDontSee('More');
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){if($action!=='resume_observation'){commerceReject(fn()=>$run($check->fresh(),$action));}}
$stoppedPage->callAction('resume_observation')->assertNotified('Sync resumed')->assertSee('Sync resumed')->assertDontSee('Stopped after 5 attempts.');
expect(str_contains($stoppedPage->html(),'wire:poll.2s.keep-alive="pollManagement"'),'An open page follows the resumed scheduler read');
DB::table('domains_observation_work')->where('id',$stopped)->update(['safe_code'=>'refreshed']);
$stoppedPage->call('pollManagement');
expect(!str_contains($stoppedPage->html(),'wire:poll.'),'Completed scheduled read stops polling');
DB::table('domains_observation_work')->where('id',$stopped)->update(['safe_code'=>null]);
same(1,(int)DB::table('domains_observation_work')->where('id',$stopped)->value('resumes'),'Stopped domain work remains resumable after purchase completion');
unset($stoppedPage);gc_collect_cycles();
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->assertDontSee('More');
commerceReject(fn()=>$run($check->fresh(),'resume_observation'));
// 875ef5d4 keeps a healthy queued retry automatic; only stopped work needs staff resume.
ObservationRecords::hold($check->domain->fresh(),'records_unavailable',$clock->now());
$retryBefore=(array)DB::table('domains_observation_work')->where('id',$stopped)->first();
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionHidden('resume_observation');
same($retryBefore,(array)DB::table('domains_observation_work')->where('id',$stopped)->first(),'Viewing a queued record hold cannot reset its retry');
expect(ObservationRecords::held($check->domain->fresh()),'The record hold waits for a real successful read');
DB::table('domains_observation_work')->where('id',$stopped)->update(['safe_code'=>'records_incomplete']);
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionVisible('resume_observation')
    ->callAction('resume_observation')->assertNotified('Sync resumed');
expect(!ObservationRecords::held($check->domain->fresh()),'Domain recovery clears the record hold');
same(2,(int)DB::table('domains_observation_work')->where('id',$stopped)->value('resumes'),'Stopped record hold resumes its current job');
// Sync now reads outside a job row and can set a hold, so with no row its own button is the way back, and a complete read clears the hold.
DB::table('domains_observation_work')->where('id',$stopped)->delete();ObservationRecords::hold($check->domain->fresh(),'records_unavailable',$clock->now());
$noRow=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionHidden('resume_observation')->assertSee('Use Sync now.')->assertDontSee('Use Resume sync');
$noRow->assertActionVisible('sync_now');
unset($noRow);gc_collect_cycles();
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionHidden('resume_observation');
// Any domain can stop syncing. One with no request resumes on its own page, through the same writer, audited in its history.
[$solo]=$prepare(placeholderComFixtureName('staff-solo-placeholder.com'));$run($solo,'recheck');$soloDomain=$solo->domain->fresh();
DB::table('domains_observation_work')->where('domain_id',$soloDomain->id)->delete();DB::table('domains_operations')->where('id',$solo->id)->delete();
$soloWork=DB::table('domains_observation_work')->insertGetId(['server_id'=>$soloDomain->server_id,'account_fingerprint'=>$soloDomain->account_fingerprint,'domain_id'=>$soloDomain->id,
    'generation'=>$soloDomain->authority_generation,'phase'=>\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::phase($soloDomain),'due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
$soloPage=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$soloDomain->id])->assertActionVisible('resume_observation')->assertSee('Use Resume sync below.')->assertDontSeeHtml('fi-callout');
commerceReject(fn()=>app(ObservationWork::class)->resume($soloWork,'staff_review',new StaffSyncReview((int)$staff->id,(int)$soloDomain->id,'stale',StaffOperationReview::ACTIONS['resume_observation'],$clock)));
commerceReject(fn()=>app(ObservationWork::class)->resume($soloWork,'staff_review',new StaffSyncReview((int)$staff->id+1,(int)$soloDomain->id,StaffSyncReview::stamp($soloDomain->fresh()),StaffOperationReview::ACTIONS['resume_observation'],$clock)));
same(0,(int)DB::table('domains_observation_work')->where('id',$soloWork)->value('resumes'),'A stale or foreign review resumes nothing');
$soloPage->callAction('resume_observation')->assertNotified('Sync resumed');
same(1,(int)DB::table('domains_observation_work')->where('id',$soloWork)->value('resumes'),'No-request domain resumes');
unset($soloPage);gc_collect_cycles();
$soloEntries=\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::entries($soloDomain->fresh());
same(1,count(array_filter($soloEntries,fn(array $row)=>$row['event']==='Sync resumed'&&$row['sub']===null)),'History names the resume once');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$soloDomain->id])->assertActionHidden('resume_observation');
echo "Native staff-operation-sync passed.\n";
$nativeSuccessful=true;
