<?php
// Native staff workflow boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use App\Models\{Credit,InvoiceTransaction};
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{CataloguePreview,EnomTestReconciliationReader};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationRecords,ObservationSchema,ObservationWork,OperationReconciliation,ReconciliationEvidence,RegistrarAnswers,RegistrarCheck,Refunds,RenewalCompletion,RenewalReplacements,StaffOperationReview,StaffOperations,StaffSettingsReview,StaffSyncReview};
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
try{
// Native re-check calls the existing read-only reconciliation engine; actual proof alone can complete.
[$check]=$prepare(placeholderComFixtureName('staff-recheck-placeholder.com'));$calls=count($observation->calls);
$checkedPage=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->callAction('recheck')->assertNotified('Registrar checked')
    ->assertSee('Completed')->assertSee('Registrar checked')->assertDontSee('has not confirmed')->assertDontSee('More')->assertActionHidden('recheck');
same('succeeded',$check->fresh()->state,'Reconciliation engine verifies actual charge and expiry');expect(count($observation->calls)>$calls,'Only explicit action reads');
$history=$check->fresh()->policy['staff_actions'];same('read_finished',end($history)['phase'],'Read result audited');
// Captured unknown/sent replies use the same audited staff route as accepted orders.
$preflight->cost='8.95';
[$captured]=$prepare(placeholderComFixtureName('staff-captured-placeholder.com'),'captured');
$original=$captured->attempt;
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$captured->id])->callAction('recheck')->assertNotified('Registrar checked');
same('succeeded',$captured->fresh()->state,'Staff settles captured order without another purchase');
same($original,array_diff_key($captured->fresh()->attempt,['reconciliation'=>true]),'Staff recovery preserves original unknown execution');
// The send guard refuses before a response. The page must say that and name a next step.
[$deferred]=$prepare(placeholderComFixtureName('staff-deferred-placeholder.com'),'captured');
$observation->fault='send_guard';
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$deferred->id])->callAction('recheck')
    ->assertNotified('Check delayed')->assertSee('Check delayed')->assertSee('Our sending settings paused this check.')->assertSee('The next check is at')->assertSee('UTC')->assertDontSee('Status checked');
same('deferred',$deferred->fresh()->policy['status_check']['outcome'],'Guard refusal is saved as deferred, never checked');
expect($deferred->fresh()->policy['status_check']['next_at']>$clock->now()->getTimestamp(),'Deferred read has an automatic next due time');
// A lost reply retains its staff answer boundary and cannot claim a completed read.
[$uncheckable]=$prepare(placeholderComFixtureName('staff-no-reply-placeholder.com'),'null');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$uncheckable->id])->callAction('recheck')
    ->assertNotified('Enom has not answered yet')->assertSee('Ask Enom to check this attempt.')->assertSee('You can record Enom’s answer after Oct 19, 2026, 12:00 PM UTC.')->assertDontSee('Status checked');
same('awaiting_answer',$uncheckable->fresh()->policy['status_check']['outcome'],'Lost reply retains the registrar answer state');
same((new \DateTimeImmutable($uncheckable->attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp(),$uncheckable->fresh()->policy['status_check']['answer_after'],'Staff answer waits 24 hours from this dispatch');
expect(in_array(ReadCommand::Info,$observation->calls,true),'A lost Enom reply still reads the domain before staff ask for a case');
[$transportFailure]=$prepare(placeholderComFixtureName('staff-failed-read-placeholder.com'),'captured');
$observation->fault='transport';
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$transportFailure->id])->callAction('recheck')
    ->assertNotified('Cannot check registrar')->assertSee('Support can check the original order.');
[$lastPoll]=$prepare(placeholderComFixtureName('staff-last-read-placeholder.com'),'captured');
$policy=$lastPoll->policy;$policy['reconciliation']['polls']=4;$lastPoll->update(['policy'=>$policy]);$observation->fault='wrong-charge';
$reconcile->run($lastPoll->id);
same(null,$lastPoll->fresh()->policy['status_check']['next_at'],'Exhausted reads promise no automatic retry');

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
// Domain recovery appears for stopped scheduled work, never on the completed request.
// Completion already queued the initial read. Stop that exact row instead of creating a duplicate.
$stoppedKey=['domain_id'=>$check->domain_id,'generation'=>$check->authority_generation,'phase'=>hash('sha256',$check->operation_key)];
DB::table('domains_observation_work')->updateOrInsert($stoppedKey,['server_id'=>$check->server_id,'account_fingerprint'=>$check->account_fingerprint,'due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
$stopped=DB::table('domains_observation_work')->where($stoppedKey)->value('id');
$stoppedPage=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionVisible('resume_observation');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->assertDontSee('More');
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){if($action!=='resume_observation'){commerceReject(fn()=>$run($check->fresh(),$action));}}
$stoppedPage->callAction('resume_observation')->assertNotified('Sync resumed');
same(1,(int)DB::table('domains_observation_work')->where('id',$stopped)->value('resumes'),'Stopped domain work remains resumable after purchase completion');
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
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$check->domain_id])->assertActionHidden('resume_observation');
// Any domain can stop syncing. One with no request resumes on its own page, through the same writer, audited in its history.
[$solo]=$prepare(placeholderComFixtureName('staff-solo-placeholder.com'));$run($solo,'recheck');$soloDomain=$solo->domain->fresh();
DB::table('domains_observation_work')->where('domain_id',$soloDomain->id)->delete();DB::table('domains_operations')->where('id',$solo->id)->delete();
$soloWork=DB::table('domains_observation_work')->insertGetId(['server_id'=>$soloDomain->server_id,'account_fingerprint'=>$soloDomain->account_fingerprint,'domain_id'=>$soloDomain->id,
    'generation'=>$soloDomain->authority_generation,'phase'=>\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::phase($soloDomain),'due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
$soloPage=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$soloDomain->id])->assertActionVisible('resume_observation')->assertSee('Use Sync now.');
same(1,substr_count($soloPage->html(),'The registrar read stopped.'),'Read stop is stated once; an independent monitoring setup callout may remain');
commerceReject(fn()=>app(ObservationWork::class)->resume($soloWork,'staff_review',new StaffSyncReview((int)$staff->id,(int)$soloDomain->id,'stale',StaffOperationReview::ACTIONS['resume_observation'],$clock)));
commerceReject(fn()=>app(ObservationWork::class)->resume($soloWork,'staff_review',new StaffSyncReview((int)$staff->id+1,(int)$soloDomain->id,StaffSyncReview::stamp($soloDomain->fresh()),StaffOperationReview::ACTIONS['resume_observation'],$clock)));
same(0,(int)DB::table('domains_observation_work')->where('id',$soloWork)->value('resumes'),'A stale or foreign review resumes nothing');
$soloPage->callAction('resume_observation')->assertNotified('Sync resumed');
same(1,(int)DB::table('domains_observation_work')->where('id',$soloWork)->value('resumes'),'No-request domain resumes');
$soloEntries=\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::entries($soloDomain->fresh());
same(1,count(array_filter($soloEntries,fn(array $row)=>$row['event']==='Sync resumed'&&$row['sub']===null)),'History names the resume once');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$soloDomain->id])->assertActionHidden('resume_observation');
// Independent literal boundaries for both shared acquisition actions.
foreach(['register','transfer'] as $action){
    [$boundary]=$prepare(placeholderComFixtureName('staff-'.$action.'-boundary-placeholder.com'));
    foreach([4,5] as $polls){
        $policy=$boundary->fresh()->policy;$policy['reconciliation']=['polls'=>$polls];$policy['renewal_polls']=0;
        $boundary->update(['policy'=>$policy]);DB::table('domains_operations')->where('id',$boundary->id)->update(['action'=>$action]);
        $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$boundary->id]);
        if($polls===4){$page->assertActionVisible('recheck')->assertActionHidden('resume_reads');}
        else{$page->assertActionHidden('recheck')->assertActionVisible('resume_reads');}
    }
}

// Read resume retains original attempt/funding; limits and current generation remain engine-owned.
[$resume]=$prepare(placeholderComFixtureName('staff-resume-placeholder.com'));$p=$resume->policy;$p['reconciliation']=['polls'=>5];$resume->update(['policy'=>$p]);$original=$resume->fresh()->attempt;
$resumedPage=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$resume->id])->callAction('resume_reads')->assertNotified('Status checks restarted')
    ->assertSee('Status checks restarted')->assertActionHidden('resume_reads')->assertActionVisible('recheck');
same('scheduled',$resume->fresh()->policy['status_check']['outcome'],'Resume clears stale status');same($clock->now()->getTimestamp(),(int)DB::table('domains_runtime_operations')->where('operation_id',$resume->id)->value('due_at'),'Resume is due immediately');same(0,$resume->fresh()->policy['reconciliation']['polls'],'Bounded read budget reset');same($original,$resume->fresh()->attempt,'Read resume cannot reset attempt');
// Registration confirmation makes record sync eligible and queues its initial work.
$resumedPage->callAction('recheck')->assertNotified('Registrar checked');$resume=$resume->fresh();
$jobKey=['domain_id'=>$resume->domain_id,'generation'=>$resume->authority_generation,'phase'=>\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::phase($resume->domain->fresh())];
DB::table('domains_observation_work')->updateOrInsert($jobKey,['server_id'=>$resume->server_id,'account_fingerprint'=>$resume->account_fingerprint,'due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
$job=DB::table('domains_observation_work')->where($jobKey)->value('id');
// Resume sync lives on the domain page; the request page does not offer it.
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$resume->id])->assertDontSee('Resume sync');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$resume->domain_id])->callAction('resume_observation')->assertNotified('Sync resumed');
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
    DB::table('domains_observation_work')->where('id',$job)->update(['safe_code'=>'read_unavailable','tries'=>5]);
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
[$renew,$domain,$binding]=$makeRenewal(placeholderComFixtureName('staff-renewal-placeholder.com'));$renew->update(['state'=>'held','safe_code'=>'renewal_funding_or_authority']);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$renew->id])->callAction('release')->assertNotified('Hold released');
same('queued',$renew->fresh()->state,'Held renewal re-release');$run($renew->fresh(),'hold');$run($renew->fresh(),'refund');
// Exact old credit settlement automatically releases an ordinary replacement before expiry.
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$automatic=$billing->invoice($domain->id,$user);
expect($automatic->id!==$renew->invoice_id,'Exact settled refund creates replacement without a staff step');
expect(RenewalReplacements::reviewed($renew->fresh(),$domain->fresh()),'Automatic exact-settlement proof retained');
same(null,$renew->fresh()->policy['renewal_replacement']['actor_id'],'Automatic recovery never invents a staff actor');
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
same([(int)$renew->id],RenewalReplacements::activityIds($domain->fresh()),'Late refunded-debt activity exposes the affected request ID');

// The late payment shows on the refunded request it landed on; the replacement still offers Check status.
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$renew->id])->assertSee('Payment received after credit. Check the invoice.');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$replacement->id])->assertDontSee('Payment received after credit')
    ->callAction('recheck')->assertNotified('Registrar checked');
same('succeeded',$replacement->fresh()->state,'Staff readback completes sealed replacement after old late payment');
// Before admission, changed old settlement still blocks a new obligation.
[$late,$lateDomain]=$makeRenewal(placeholderComFixtureName('staff-replacement-late-placeholder.com'));$run($late,'hold');$run($late->fresh(),'refund');$run($late->fresh(),'replacement');
InvoiceTransaction::create(['invoice_id'=>$late->invoice_id,'amount'=>'0.01','fee'=>'0.00','status'=>'succeeded']);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');commerceReject(fn()=>$billing->invoice($lateDomain->id,$user));
// Exhausted renewal read recovery is separately bounded and preserves all financial/send evidence.
[$readRenew,$readDomain,$binding]=$makeRenewal(placeholderComFixtureName('staff-renewal-resume-placeholder.com'));
$runRenewal($readRenew,$binding);$readRenew->refresh();$policy=$readRenew->policy;$policy['renewal_polls']=5;$readRenew->update(['policy'=>$policy]);
foreach([4,5] as $polls){
    $policy=$readRenew->fresh()->policy;$policy['renewal_polls']=$polls;$policy['reconciliation']=['polls'=>0];$readRenew->update(['policy'=>$policy]);
    $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$readRenew->id]);
    if($polls===4){$page->assertActionVisible('recheck')->assertActionHidden('resume_reads');}
    else{$page->assertActionHidden('recheck')->assertActionVisible('resume_reads');}
}

$before=[$readRenew->fresh()->attempt,$readRenew->fresh()->funding,$readRenew->domain->fresh()->reservation_name];
$renewPage=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$readRenew->id])->callAction('resume_reads')->assertNotified('Status checks restarted')
    ->assertActionHidden('resume_reads')->assertActionVisible('recheck')->assertSee('Status checks restarted');
same(0,$readRenew->fresh()->policy['renewal_polls'],'Renewal read budget explicitly resumed');
same($before,[$readRenew->fresh()->attempt,$readRenew->fresh()->funding,$readRenew->domain->fresh()->reservation_name],'Renewal resume preserves financial/send evidence');
$renewPage->callAction('recheck')->assertNotified('Registrar checked')->assertSee('Completed')->assertDontSee('More')->assertDontSee('has not confirmed');
same('succeeded',$readRenew->fresh()->state,'Renewal readback completes original attempt');
commerceReject(fn()=>$run($readRenew->fresh(),'disposition'));
[$limitRenew,,$binding]=$makeRenewal(placeholderComFixtureName('staff-renewal-resume-limit-placeholder.com'));$runRenewal($limitRenew,$binding);$limitRenew->refresh();
$policy=$limitRenew->policy;$policy['renewal_resumptions']=array_fill(0,20,['prior_polls'=>5,'at'=>$clock->now()->format(DATE_ATOM)]);$limitRenew->update(['policy'=>$policy]);
commerceReject(fn()=>$run($limitRenew,'resume_reads'));
commerceReject(fn()=>$limitRenew->fresh()->update(['policy'=>array_diff_key($limitRenew->fresh()->policy,['renewal_resumptions'=>true])]));
// An Enom lost reply reads the domain first. The read keeps facts only: no charge, completion or money change.
[$lost,$binding]=$prepare(placeholderComFixtureName('staff-lost-read-placeholder.com'),'absent');
$clock->instant=$clock->now()->modify('+25 hours');
DB::table('domains_accounts')->where('server_id',$lost->server_id)->update(['processing_enabled'=>false]);
$staff->role->update(['permissions'=>[...$all,Staff::POLICY,'admin.domains.settings.enom_answer']]);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$lost->id])->assertActionHidden('enom_answer')->assertSee('Registrar check pending');
// The writer repeats the page rule: no Enom answer before one read of this attempt.
$unread=static function($op,string $why)use($staff,$clock):void{
    $r=new StaffSettingsReview($staff->id,$op->server_id,null,'enom_answer',StaffSettingsReview::ticket($staff->id,$op->server_id,null,'enom_answer',StaffSettingsReview::stamp($op->server_id,null)),'Record final synthetic Enom answer');
    $kept=$op->fresh()->getRawOriginal();$refusal=null;
    try{RegistrarAnswers::record($r,['operation_id'=>$op->id,'attempt_hash'=>RegistrarAnswers::identity($op->fresh()),'outcome'=>'accepted','order_reference'=>'9001','case_reference'=>'synthetic-case-123',
        'evidence'=>'Enom checked the exact account, domain and attempt time.','confirmed'=>true],$clock->now());}catch(DomainException $error){$refusal=$error->getMessage();}
    same('Use Check status to read this attempt first, then record the registrar’s answer.',$refusal,$why.' refuses the answer before a read');
    same($kept,$op->fresh()->getRawOriginal(),$why.' stays unchanged');
};
$unread($lost,'An Enom lost registration reply');
$said=RegistrarCheck::forCustomer($lost->fresh());
same('Registration pending',$said['title'],'Before the first read the customer sees the pending registration line');
expect(!str_contains($said['body'],'Check status'),'Customers never see the staff Check status step');
// A read our send check stops before sending is no read of this attempt.
$observation->fault='send_guard';$reconcile->run($lost->id);$observation->fault='';
same(false,$lost->fresh()->policy['status_check']['read_attempted'],'A read refused before sending is not a read of this attempt');
$unread($lost,'A read refused before sending');
$before=[$lost->attempt,$lost->funding,$lost->domain->reservation_name,$credit()];
$reconcile->run($lost->id);
expect(in_array(ReadCommand::Info,$observation->calls,true),'Automatic recovery reads the domain before staff ask for a case');
expect(!in_array(ReadCommand::Order,$observation->calls,true)&&!in_array(ReadCommand::Orders,$observation->calls,true),'Missing order identity and unaccepted history never allow an order search');
$lost->refresh();
same(['owned'=>true,'expiry'=>'2027-10-18','expiry_precision'=>'date','charge_confirmed'=>false],$lost->policy['status_check']['facts'],'Domain facts are kept without inventing an original charge');
same('awaiting_answer',$lost->policy['status_check']['outcome'],'Unknown original charge still needs the registrar answer');
same(1,$lost->policy['reconciliation']['polls'],'A completed read of a lost reply counts toward the read limit');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$lost->id])->assertActionVisible('enom_answer')->assertSee('The original charge is still unconfirmed.');
same('uncertain',$lost->state,'A found domain cannot settle a lost purchase');
same(null,$lost->cost['actual']??null,'Unknown charge stays unknown');
same($before,[$lost->attempt,$lost->funding,$lost->domain->fresh()->reservation_name,$credit()],'Reads keep the original attempt, reservation and payment');
// The installed reader allows the same sealed-attempt read while its history gate stays closed.
$driver=$readBindings->reader($lost->server_id);
$reader=new EnomTestReconciliationReader(new CataloguePreview($driver),$driver);
$context=new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock);
same(true,$reader->reconcile(ReconciliationEvidence::attempt($lost,$lost->domain->fresh(),$binding),$context)->result()->evidence()->domain?->owned,'Installed reader reads a sealed lost attempt through a paid-changes hold');
// A failed read records no ownership or charge.
$observation->fault='transport';$reconcile->run($lost->id);
same([],$lost->fresh()->policy['status_check']['facts'],'A failed read cannot repeat earlier facts as current');
same(null,$lost->fresh()->cost['actual']??null,'A failed read cannot settle the fee');
// Once the read limit stops new reads, the earlier read of this attempt still lets staff record the answer.
$policy=$lost->fresh()->policy;$policy['reconciliation']['polls']=OperationReconciliation::POLL_LIMIT;$lost->fresh()->update(['policy'=>$policy]);$observation->fault='';$observation->calls=[];
$reconcile->run($lost->id);
same([],$observation->calls,'The read limit stops another read');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$lost->id])->assertActionVisible('enom_answer')->assertDontSee('Registrar check pending');
// A refused read and a resume keep that earlier read of this attempt.
$policy=$lost->fresh()->policy;$policy['reconciliation']['polls']=0;$lost->fresh()->update(['policy'=>$policy]);
$observation->fault='send_guard';$reconcile->run($lost->id);$observation->fault='';
same(true,$lost->fresh()->policy['status_check']['read_attempted'],'A refused read keeps the earlier read of this attempt');
$policy=$lost->fresh()->policy;$policy['reconciliation']['polls']=OperationReconciliation::POLL_LIMIT;$lost->fresh()->update(['policy'=>$policy]);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$lost->id])->callAction('resume_reads')->assertNotified('Status checks restarted')->assertActionVisible('enom_answer');
same(true,$lost->fresh()->policy['status_check']['read_attempted'],'A resume keeps the earlier read of this attempt');
// A lost renewal reply outside its history window can still read the domain.
DB::table('domains_accounts')->where('server_id',$lost->server_id)->update(['processing_enabled'=>true]);
[$lostRenew,$lostDomain,$binding]=$makeRenewal(placeholderComFixtureName('staff-lost-renewal-placeholder.com'));
$state->lost=true;$runRenewal($lostRenew,$binding);$lostRenew->refresh();$state->calls=[];
$clock->instant=$clock->now()->modify('+25 hours');
DB::table('domains_accounts')->where('server_id',$lostRenew->server_id)->update(['processing_enabled'=>false]);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$lostRenew->id])->assertActionHidden('enom_answer')->assertSee('Registrar check pending')->assertDontSee('too old to check in its order history');
$unread($lostRenew,'An Enom lost renewal reply');
// Too old for order history, the notice still offers the first read: Check status, or the resume once reads are used up.
same('recheck',RequestFacts::notice($lostRenew->fresh())['action']??null,'An unread too-old renewal offers Check status');
$policy=$lostRenew->fresh()->policy;$policy['renewal_polls']=OperationReconciliation::POLL_LIMIT;$lostRenew->fresh()->update(['policy'=>$policy]);
same('resume_reads',RequestFacts::notice($lostRenew->fresh())['action']??null,'An unread too-old renewal with no reads left offers the resume');
$policy['renewal_polls']=0;$lostRenew->fresh()->update(['policy'=>$policy]);
$state->during=static function(){throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit('send_guard');};
(new RenewalCompletion($renewBindings,$clock))->run($lostRenew->id);$state->during=null;$state->calls=[];
same(false,$lostRenew->fresh()->policy['status_check']['read_attempted'],'A renewal read refused before sending is not a read of this attempt');
$unread($lostRenew,'A renewal read refused before sending');
$before=[$lostRenew->attempt,$lostRenew->funding,$lostDomain->fresh()->provider_expires_at->format('Y-m-d'),$credit(),$state->writes];
(new RenewalCompletion($renewBindings,$clock))->run($lostRenew->id);
expect(in_array(ReadCommand::Info,$state->calls,true),'Automatic renewal recovery reads before staff ask for a case');
same(false,$lostRenew->fresh()->policy['status_check']['facts']['charge_confirmed'],'A later expiry is not proof of this renewal charge');
same($before,[$lostRenew->fresh()->attempt,$lostRenew->fresh()->funding,$lostDomain->fresh()->provider_expires_at->format('Y-m-d'),$credit(),$state->writes],'Unknown renewal cost keeps its original authority and money');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$lostRenew->id])->assertActionVisible('recheck')->assertActionVisible('enom_answer');
// Unreadable saved attempt data renders the request page without an answer form or a send.
$storedAttempt=$lostRenew->fresh()->getRawOriginal('attempt');$writes=$state->writes;
DB::table('domains_operations')->where('id',$lostRenew->id)->update(['attempt'=>'d29-explicit-unreadable-fixture']);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$lostRenew->id])->assertSuccessful()->assertActionHidden('enom_answer')->assertSee($lostDomain->domain);
same([false,$writes],[RegistrarAnswers::ready($lostRenew->fresh(),$clock->now()),$state->writes],'Unreadable attempt is never ready for an answer and sends nothing');
DB::table('domains_operations')->where('id',$lostRenew->id)->update(['attempt'=>$storedAttempt]);
echo "Native staff actions: permissions, confirmations, reasons, audit, stale/replay/binding, read-only reconciliation, credit and cycle replacement passed.\n";
}catch(Throwable $error){throw new RuntimeException($error->getMessage().' at '.implode(' > ',array_map(static fn($frame)=>($frame['file']??'').':'.($frame['line']??0),$error->getTrace())),0,$error);}
$nativeSuccessful=true;
