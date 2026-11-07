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
use Paymenter\Extensions\Servers\Domains\Services\ObservationWork;
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
$preflight->cost='8.95';
// Independent literal boundaries for both shared acquisition actions.
foreach(['register','transfer'] as $action){
    [$boundary]=$prepare(placeholderComFixtureName('staff-'.$action.'-boundary-placeholder.com'));
    foreach([4,5] as $polls){
        $policy=$boundary->fresh()->policy;$policy['reconciliation']=['polls'=>$polls];$policy['renewal_polls']=0;
        $boundary->update(['policy'=>$policy]);DB::table('domains_operations')->where('id',$boundary->id)->update(['action'=>$action]);
        $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$boundary->id]);
        if($polls===4){$page->assertActionVisible('recheck')->assertActionHidden('resume_reads');}
        else{$page->assertActionHidden('recheck')->assertActionVisible('resume_reads');}
        unset($page);gc_collect_cycles();
    }
}

// Read resume retains original attempt/funding; limits and current generation remain engine-owned.
[$resume]=$prepare(placeholderComFixtureName('staff-resume-placeholder.com'));$p=$resume->policy;$p['reconciliation']=['polls'=>5];$resume->update(['policy'=>$p]);$original=$resume->fresh()->attempt;
$resumedPage=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$resume->id])->callAction('resume_reads')->assertNotified('Status checks restarted')
    ->assertSee('Status checks restarted')->assertActionHidden('resume_reads')->assertActionVisible('recheck');
same('scheduled',$resume->fresh()->policy['status_check']['outcome'],'Resume clears stale status');same($clock->now()->getTimestamp(),(int)DB::table('domains_runtime_operations')->where('operation_id',$resume->id)->value('due_at'),'Resume is due immediately');same(0,$resume->fresh()->policy['reconciliation']['polls'],'Bounded read budget reset');same($original,$resume->fresh()->attempt,'Read resume cannot reset attempt');
unset($resumedPage);gc_collect_cycles();
$job=DB::table('domains_observation_work')->insertGetId(['server_id'=>$resume->server_id,'account_fingerprint'=>$resume->account_fingerprint,'domain_id'=>$resume->domain_id,
    'generation'=>$resume->authority_generation,'due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
// Resume sync lives on the domain page; the request page does not offer it.
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$resume->id])->assertDontSee('Resume sync');
$before=[$resume->fresh()->attempt,$resume->fresh()->funding,$resume->domain->fresh()->observation,(array)DB::table('domains_observation_work')->where('id',$job)->sole()];
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$resume->domain_id])->assertActionDisabled('resume_observation')
    ->assertSee('Registrar records can sync after registration is confirmed.')->assertSee('Check request #'.$resume->id);
commerceReject(fn()=>$run($resume->fresh(),'resume_observation'));
same($before,[$resume->fresh()->attempt,$resume->fresh()->funding,$resume->domain->fresh()->observation,(array)DB::table('domains_observation_work')->where('id',$job)->sole()],'Blocked pending sync retains the stop and original financial evidence');
// The existing request status check must finish registration before record sync resumes.
$run($resume->fresh(),'recheck');
same('succeeded',$resume->fresh()->state,'Supported request check completes the original registration');
DB::table('domains_observation_work')->where('id',$job)->update(['phase'=>hash('sha256',$resume->operation_key)]);
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
    $run($resume->fresh(),'resume_observation');
    same($before+1,DB::table('domains_observation_work')->where('id',$job)->value('resumes'),'Staff resumes the current job despite newer historical work');
    same(1,DB::table('domains_observation_work')->where('id',$id)->value('resumes'),'Historical saved work stays unchanged');
}
DB::table('domains_observation_work')->where('id',$job)->update(['phase'=>'missing-current-phase']);
commerceReject(fn()=>$run($resume->fresh(),'resume_observation'));
foreach ($foreign as $id) { same(1,DB::table('domains_observation_work')->where('id',$id)->value('resumes'),'No current job never falls back to historical work'); }
DB::table('domains_observation_work')->where('id',$job)->update(['phase'=>$current->phase]);
DB::table('domains_observation_work')->where('id',$job)->update(['resumes'=>20]);commerceReject(fn()=>$run($resume->fresh(),'resume_observation'));
// Exhausted renewal read recovery is separately bounded and preserves all financial/send evidence.
[$readRenew,$readDomain,$binding]=$makeRenewal(placeholderComFixtureName('staff-renewal-resume-placeholder.com'));
$runRenewal($readRenew,$binding);$readRenew->refresh();$policy=$readRenew->policy;$policy['renewal_polls']=5;$readRenew->update(['policy'=>$policy]);
foreach([4,5] as $polls){
    $policy=$readRenew->fresh()->policy;$policy['renewal_polls']=$polls;$policy['reconciliation']=['polls'=>0];$readRenew->update(['policy'=>$policy]);
    $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$readRenew->id]);
    if($polls===4){$page->assertActionVisible('recheck')->assertActionHidden('resume_reads');}
    else{$page->assertActionHidden('recheck')->assertActionVisible('resume_reads');}
    unset($page);gc_collect_cycles();
}

$before=[$readRenew->fresh()->attempt,$readRenew->fresh()->funding,$readRenew->domain->fresh()->reservation_name];
$renewPage=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$readRenew->id])->callAction('resume_reads')->assertNotified('Status checks restarted')
    ->assertActionHidden('resume_reads')->assertActionVisible('recheck')->assertSee('Status checks restarted');
same(0,$readRenew->fresh()->policy['renewal_polls'],'Renewal read budget explicitly resumed');
same($before,[$readRenew->fresh()->attempt,$readRenew->fresh()->funding,$readRenew->domain->fresh()->reservation_name],'Renewal resume preserves financial/send evidence');
$renewPage->callAction('recheck')->assertNotified('Registrar checked')->assertSee('Completed')->assertDontSee('More')->assertDontSee('has not confirmed');
unset($renewPage);gc_collect_cycles();
same('succeeded',$readRenew->fresh()->state,'Renewal readback completes original attempt');
commerceReject(fn()=>$run($readRenew->fresh(),'disposition'));
[$limitRenew,,$binding]=$makeRenewal(placeholderComFixtureName('staff-renewal-resume-limit-placeholder.com'));$runRenewal($limitRenew,$binding);$limitRenew->refresh();
$policy=$limitRenew->policy;$policy['renewal_resumptions']=array_fill(0,20,['prior_polls'=>5,'at'=>$clock->now()->format(DATE_ATOM)]);$limitRenew->update(['policy'=>$policy]);
commerceReject(fn()=>$run($limitRenew,'resume_reads'));
commerceReject(fn()=>$limitRenew->fresh()->update(['policy'=>array_diff_key($limitRenew->fresh()->policy,['renewal_resumptions'=>true])]));
echo "Native staff-operation-read-resume passed.\n";
$nativeSuccessful=true;
