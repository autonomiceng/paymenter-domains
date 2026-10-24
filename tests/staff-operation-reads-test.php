<?php
// Native staff workflow boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use App\Models\{Credit,InvoiceTransaction};
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationRecords,ObservationSchema,ObservationWork,Refunds,RenewalReplacements,StaffOperationReview,StaffOperations};
// Native re-check calls the existing read-only reconciliation engine; actual proof alone can complete.
[$check]=$prepare('staff-recheck.com');$calls=count($observation->calls);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->callAction('recheck',['reason'=>'Read original registrar outcome'])->assertNotified('Review recorded');
same('succeeded',$check->fresh()->state,'Reconciliation engine verifies actual charge and expiry');expect(count($observation->calls)>$calls,'Only explicit action reads');
$history=$check->fresh()->policy['staff_actions'];same('read_finished',end($history)['phase'],'Read result audited');
// Proven completion with retained execution/seal closes every operation-scoped action.
expect(($check->fresh()->attempt['execution']??null)!==null,'Completed fixture retains a known send response');
$closed=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->assertDontSee('Review actions')
    ->assertSee('Registrar completion confirmed. Original send evidence is retained for history.')->assertDontSee('Original attempt possibly sent');
$before=[$check->fresh()->getRawOriginal(),$check->domain->fresh()->getRawOriginal()];
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){
    $closed->assertActionHidden($action);
    commerceReject(fn()=>$run($check->fresh(),$action));
    same($before,[$check->fresh()->getRawOriginal(),$check->domain->fresh()->getRawOriginal()],'Direct completed action leaves operation and domain unchanged');
}
// Domain recovery appears for stopped scheduled work, never on the completed request.
$stopped=DB::table('domains_observation_work')->insertGetId(['server_id'=>$check->server_id,'account_fingerprint'=>$check->account_fingerprint,'domain_id'=>$check->domain_id,
    'generation'=>$check->authority_generation,'phase'=>hash('sha256',$check->operation_key),'due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
$stoppedPage=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionVisible('resume_observation');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->assertDontSee('Review actions');
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){if($action!=='resume_observation'){commerceReject(fn()=>$run($check->fresh(),$action));}}
$stoppedPage->callAction('resume_observation',['reason'=>'Resume stopped domain observations'])->assertNotified('Review recorded');
same(1,(int)DB::table('domains_observation_work')->where('id',$stopped)->value('resumes'),'Stopped domain work remains resumable after purchase completion');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->assertDontSee('Review actions');
commerceReject(fn()=>$run($check->fresh(),'resume_observation'));
// Record-only holds also recover when the current scheduler job is healthy.
ObservationRecords::hold($check->domain->fresh(),'records_unavailable',$clock->now());
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionVisible('resume_observation')
    ->callAction('resume_observation',['reason'=>'Retry paused record discovery'])->assertNotified('Review recorded');
expect(!ObservationRecords::held($check->domain->fresh()),'Domain recovery clears the record hold');
same(2,(int)DB::table('domains_observation_work')->where('id',$stopped)->value('resumes'),'Record hold resumes current healthy job');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionHidden('resume_observation');
// Read resume retains original attempt/funding; limits and current generation remain engine-owned.
[$resume]=$prepare('staff-resume.com');$p=$resume->policy;$p['reconciliation']=['polls'=>5];$resume->update(['policy'=>$p]);$original=$resume->fresh()->attempt;
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$resume->id])->callAction('resume_reads',['reason'=>'Registrar history is now available'])->assertNotified('Review recorded');
same(0,$resume->fresh()->policy['reconciliation']['polls'],'Bounded read budget reset');same($original,$resume->fresh()->attempt,'Read resume cannot reset attempt');
$job=DB::table('domains_observation_work')->insertGetId(['server_id'=>$resume->server_id,'account_fingerprint'=>$resume->account_fingerprint,'domain_id'=>$resume->domain_id,
    'generation'=>$resume->authority_generation,'due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$resume->id])->callAction('resume_observation',['reason'=>'Resume retained read work'])->assertNotified('Review recorded');
same(1,(int)DB::table('domains_observation_work')->where('id',$job)->value('resumes'),'Scheduled read resume counted');
// Selection shares the same binding/generation/phase scope as stopped-state detection.
$current=DB::table('domains_observation_work')->where('id',$job)->sole();
foreach (['server_id'=>$current->server_id+100,'account_fingerprint'=>str_repeat('a',64),'generation'=>$current->generation+1,'phase'=>'old-phase'] as $field=>$value) {
    DB::table('domains_observation_work')->where('id',$job)->update([$field=>$value]);
    same(null,ObservationWork::currentId($resume->domain->fresh()),'Foreign work cannot be selected for the current holding');
    commerceReject(fn()=>$run($resume->fresh(),'resume_observation'));
    DB::table('domains_observation_work')->where('id',$job)->update([$field=>$current->$field]);
}
// A newer historical phase or generation must not hide current resumable work.
$foreign=[];
foreach (['generation'=>$current->generation+1,'phase'=>'old-phase'] as $field=>$value) {
    $row=(array)$current;unset($row['id']);$row[$field]=$value;
    $id=DB::table('domains_observation_work')->insertGetId($row);$foreign[]=$id;
    $before=DB::table('domains_observation_work')->where('id',$job)->value('resumes');
    $run($resume->fresh(),'resume_observation');
    same($before+1,DB::table('domains_observation_work')->where('id',$job)->value('resumes'),'Staff resumes the current job despite newer historical work');
    same(1,DB::table('domains_observation_work')->where('id',$id)->value('resumes'),'Historical saved work stays unchanged');
}
DB::table('domains_observation_work')->where('id',$job)->update(['phase'=>'missing-current-phase']);
commerceReject(fn()=>$run($resume->fresh(),'resume_observation'));
foreach ($foreign as $id) { same(1,DB::table('domains_observation_work')->where('id',$id)->value('resumes'),'No current job never falls back to historical work'); }
DB::table('domains_observation_work')->where('id',$job)->update(['phase'=>$current->phase]);
DB::table('domains_observation_work')->where('id',$job)->update(['resumes'=>20]);commerceReject(fn()=>$run($resume->fresh(),'resume_observation'));
// A temporarily unfunded virgin renewal can re-release only after exact funding restoration.
[$renew,$domain,$binding]=$makeRenewal('staff-renewal.com');$renew->update(['state'=>'held','safe_code'=>'renewal_funding_or_authority']);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$renew->id])->callAction('release',['reason'=>'Funding restored and verified'])->assertNotified('Review recorded');
same('queued',$renew->fresh()->state,'Held renewal re-release');$run($renew->fresh(),'hold');$run($renew->fresh(),'refund');
// Crediting never implicitly frees the cycle. Replacement needs its own explicit reviewed authority.
// Owner requests end at the confirmed expiry, so read the retained obligation inside the request window.
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');same($renew->invoice_id,$billing->invoice($domain->id,$user)->id,'Refund retains original obligation before explicit review');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$renew->id])->callAction('replacement',['reason'=>'Replace exactly refunded unsuitable cycle'])->assertNotified('Review recorded');
expect(RenewalReplacements::reviewed($renew->fresh(),$domain->fresh()),'Explicit replacement proof retained');
same([],RenewalReplacements::activity($domain->fresh()),'Unchanged refunded debt shows no false financial warning');
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$invoice=$billing->invoice($domain->id,$user);
expect($invoice->id!==$renew->invoice_id,'Replacement creates separate invoice, keeps original');
$replacement=Paymenter\Extensions\Servers\Domains\Models\Operation::where('invoice_id',$invoice->id)->sole();
expect(str_contains($replacement->operation_key,':replacement:'),'Replacement key is distinct');same('awaiting_payment',$replacement->state,'Replacement is never sent or implicitly funded');
\App\Helpers\ExtensionHelper::addPayment($invoice,null,$replacement->amount);
$clock->instant=new DateTimeImmutable('2027-10-18T11:59:00Z');same('pending',$runRenewal($replacement,$binding)->state,'Replacement sealed normally');
InvoiceTransaction::create(['invoice_id'=>$renew->invoice_id,'amount'=>'0.01','fee'=>'0.00','status'=>'succeeded']);
// The clock is on the expiry date, where owner requests stop; the scheduled path still reuses issued debt.
same($invoice->id,$billing->invoice($domain->id)->id,'Late old payment never duplicates admitted replacement');
expect(RenewalReplacements::activity($domain->fresh())!==[],'Late old financial activity is a read-only review fact');
$completion=app(\Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion::class);$state->fail=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Info;
$calls=count($state->calls);same('pending',$completion->run($replacement->id)->state,'Engine read remains available after late old payment');expect(count($state->calls)>$calls,'No global lock-time freeze');
$state->fail=null;$state->expiry='2028-10-18';$state->charge='8.00';
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$replacement->id])->assertSee('Late financial or billing activity on refunded debt')
    ->callAction('recheck',['reason'=>'Verify sealed replacement despite old late payment'])->assertNotified('Review recorded');
same('succeeded',$replacement->fresh()->state,'Staff readback completes sealed replacement after old late payment');
// Before admission, changed old settlement still blocks a new obligation.
[$late,$lateDomain]=$makeRenewal('staff-replacement-late.com');$run($late,'hold');$run($late->fresh(),'refund');$run($late->fresh(),'replacement');
InvoiceTransaction::create(['invoice_id'=>$late->invoice_id,'amount'=>'0.01','fee'=>'0.00','status'=>'succeeded']);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');commerceReject(fn()=>$billing->invoice($lateDomain->id,$user));
// Exhausted renewal read recovery is separately bounded and preserves all financial/send evidence.
[$readRenew,$readDomain,$binding]=$makeRenewal('staff-renewal-resume.com');
$runRenewal($readRenew,$binding);$readRenew->refresh();$policy=$readRenew->policy;$policy['renewal_polls']=5;$readRenew->update(['policy'=>$policy]);
$before=[$readRenew->fresh()->attempt,$readRenew->fresh()->funding,$readRenew->domain->fresh()->reservation_name];
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$readRenew->id])->callAction('resume_reads',['reason'=>'Original renewal history is now readable'])->assertNotified('Review recorded');
same(0,$readRenew->fresh()->policy['renewal_polls'],'Renewal read budget explicitly resumed');
same($before,[$readRenew->fresh()->attempt,$readRenew->fresh()->funding,$readRenew->domain->fresh()->reservation_name],'Renewal resume preserves financial/send evidence');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$readRenew->id])->callAction('recheck',['reason'=>'Read original sealed renewal evidence'])->assertNotified('Review recorded');
same('succeeded',$readRenew->fresh()->state,'Renewal readback completes original attempt');
commerceReject(fn()=>$run($readRenew->fresh(),'disposition'));
[$limitRenew,,$binding]=$makeRenewal('staff-renewal-resume-limit.com');$runRenewal($limitRenew,$binding);$limitRenew->refresh();
$policy=$limitRenew->policy;$policy['renewal_resumptions']=array_fill(0,20,['prior_polls'=>5,'at'=>$clock->now()->format(DATE_ATOM)]);$limitRenew->update(['policy'=>$policy]);
commerceReject(fn()=>$run($limitRenew,'resume_reads'));
commerceReject(fn()=>$limitRenew->fresh()->update(['policy'=>array_diff_key($limitRenew->fresh()->policy,['renewal_resumptions'=>true])]));
echo "Native staff actions: permissions, confirmations, reasons, audit, stale/replay/binding, read-only reconciliation, credit and cycle replacement passed.\n";
$nativeSuccessful=true;
