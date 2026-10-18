<?php
// Native staff workflow boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use App\Models\{Credit,InvoiceTransaction};
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema,ObservationWork,Refunds,RenewalReplacements,StaffOperationReview,StaffOperations};
// Native re-check calls the existing read-only reconciliation engine; actual proof alone can complete.
[$check]=$prepare('staff-recheck.com');$calls=count($observation->calls);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->callAction('recheck',['reason'=>'Read original registrar outcome'])->assertNotified('Review recorded');
same('succeeded',$check->fresh()->state,'Reconciliation engine verifies actual charge and expiry');expect(count($observation->calls)>$calls,'Only explicit action reads');
$history=$check->fresh()->policy['staff_actions'];same('read_finished',end($history)['phase'],'Read result audited');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->callAction('disposition',['reason'=>'Reviewed authoritative completed purchase'])->assertNotified('Review recorded');
// Read resume retains original attempt/funding; limits and current generation remain engine-owned.
[$resume]=$prepare('staff-resume.com');$p=$resume->policy;$p['reconciliation']=['polls'=>5];$resume->update(['policy'=>$p]);$original=$resume->fresh()->attempt;
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$resume->id])->callAction('resume_reads',['reason'=>'Registrar history is now available'])->assertNotified('Review recorded');
same(0,$resume->fresh()->policy['reconciliation']['polls'],'Bounded read budget reset');same($original,$resume->fresh()->attempt,'Read resume cannot reset attempt');
$job=DB::table('domains_observation_work')->insertGetId(['server_id'=>$resume->server_id,'account_fingerprint'=>$resume->account_fingerprint,'domain_id'=>$resume->domain_id,
    'generation'=>$resume->authority_generation,'due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$resume->id])->callAction('resume_observation',['reason'=>'Resume retained read work'])->assertNotified('Review recorded');
same(1,(int)DB::table('domains_observation_work')->where('id',$job)->value('resumes'),'Scheduled read resume counted');
DB::table('domains_observation_work')->where('id',$job)->update(['resumes'=>20]);commerceReject(fn()=>$run($resume->fresh(),'resume_observation'));
// A temporarily unfunded virgin renewal can re-release only after exact funding restoration.
[$renew,$domain,$binding]=$makeRenewal('staff-renewal.com');$renew->update(['state'=>'held','safe_code'=>'renewal_funding_or_authority']);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$renew->id])->callAction('release',['reason'=>'Funding restored and verified'])->assertNotified('Review recorded');
same('queued',$renew->fresh()->state,'Held renewal re-release');$run($renew->fresh(),'hold');$run($renew->fresh(),'refund');
// Crediting never implicitly frees the cycle. Replacement needs its own explicit reviewed authority.
same($renew->invoice_id,$billing->invoice($domain->id,$user)->id,'Refund retains original obligation before explicit review');
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
same($invoice->id,$billing->invoice($domain->id,$user)->id,'Late old payment never duplicates admitted replacement');
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
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$readRenew->id])->callAction('disposition',['reason'=>'Review verified completed renewal'])->assertNotified('Review recorded');
[$limitRenew,,$binding]=$makeRenewal('staff-renewal-resume-limit.com');$runRenewal($limitRenew,$binding);$limitRenew->refresh();
$policy=$limitRenew->policy;$policy['renewal_resumptions']=array_fill(0,20,['prior_polls'=>5,'at'=>$clock->now()->format(DATE_ATOM)]);$limitRenew->update(['policy'=>$policy]);
commerceReject(fn()=>$run($limitRenew,'resume_reads'));
commerceReject(fn()=>$limitRenew->fresh()->update(['policy'=>array_diff_key($limitRenew->fresh()->policy,['renewal_resumptions'=>true])]));
echo "Native staff actions: permissions, confirmations, reasons, audit, stale/replay/binding, read-only reconciliation, credit and cycle replacement passed.\n";
$nativeSuccessful=true;
