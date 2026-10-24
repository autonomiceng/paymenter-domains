<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory;
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery,StaffOperationReview,TransferInputs};

same('Missing: phone, city, contact details. Update the customer account details.',
    (new \Paymenter\Extensions\Servers\Domains\Services\ContactValidation(['phone','contacts.registrant.phone','city','unrecognized']))->missingMessage(),'Undotted and dotted contact fields keep their complete leaf and deduplicate');

// Decode failures inside contact eligibility retain the saved-data repair notice.
$user->properties()->where('key','phone')->update(['value'=>'']);
$damaged=$new('staff-corrupt-contact-placeholder.com',true,true);ContactRecovery::hold($damaged->id);
$user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);
foreach([[$damaged,'payload'],[$damaged->domain,'snapshot']] as [$record,$field]){
    $saved=$record->getRawOriginal($field);$query=DB::table($record->getTable())->where('id',$record->id);
    $query->update([$field=>'not-encrypted']);
    try{
        \Illuminate\Support\Facades\Auth::setUser($staff);
        $row=$damaged->fresh();$facts=\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($row);
        expect($facts['unreadable']??false,'Unreadable contact '.$field.' remains a decode failure');
        Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release')->assertSee("Can't read saved data. Check the app encryption key.");
    }finally{$query->update([$field=>$saved]);}
}

// An actual paid contact hold keeps the checkout snapshot until audited owner recollection.
foreach(['register','transfer'] as $kind){
    $user->properties()->where('key','phone')->update(['value'=>'']);
    $op=$new('staff-contact-'.$kind.'-placeholder.com',true,true,$kind);ContactRecovery::hold($op->id);$op=$op->fresh();
    same('contact_details_required',$op->safe_code,'Real contact hold: '.$kind);
    $before=$op->getRawOriginal();$deadline=TransferInputs::deadline($op,$clock->now())?->format(DATE_ATOM);$money=nativeFinancialSnapshot($op);$key=$ticket($op,'release');
    try{$run($op,'release');throw new RuntimeException('Expected incomplete contact refusal.');}
    catch(DomainException $error){same('Missing: phone. Update the customer account details.',$error->getMessage(),'Incomplete contact refusal names fields');}
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('release')->assertSee('Missing: phone. Update the customer account details.');
    same($before,$op->fresh()->getRawOriginal(),'Incomplete contact refusal leaves no audit or snapshot change');
    $user->properties()->where('key','city')->update(['value'=>'']);
    try{$run($op,'release');throw new RuntimeException('Expected multiple missing fields.');}
    catch(DomainException $error){same('Missing: city, phone. Update the customer account details.',$error->getMessage(),'One line lists distinct fields, never contact values');}
    $user->properties()->where('key','city')->update(['value'=>'Seattle']);
    $user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);
    same('',$op->fresh()->payload['contacts']['registrant']['phone'],'Profile edit alone never changes checkout contacts');
    $stable=$op->fresh()->getRawOriginal();
    foreach(['permission','stale','expired','foreign','wrong-action'] as $refusal){
        $key=$ticket($op,'release');
        if($refusal==='permission'){$staff->role->update(['permissions'=>array_diff($all,['admin.domains.operations.release'])]);}
        if($refusal==='stale'){$op->fresh()->update(['policy'=>[...$op->policy,'synthetic_review'=>true]]);}
        if($refusal==='expired'){$clock->instant=$clock->now()->modify('+901 seconds');}
        if($refusal==='foreign'){$key=StaffOperationReview::ticket($staff->id,$op->domain_id+1,$op->id,'release',StaffOperationReview::stamp($op,$op->domain),$clock);}
        if($refusal==='wrong-action'){$key=$ticket($op,'hold');}
        $unchanged=$op->fresh()->getRawOriginal();commerceReject(fn()=>$run($op,'release',$key));
        same($unchanged,$op->fresh()->getRawOriginal(),'Contact review refusal changes nothing: '.$refusal);
        $staff->role->update(['permissions'=>$all]);
        if($refusal==='expired'){$clock->instant=$clock->now()->modify('-901 seconds');}
        DB::table('domains_operations')->where('id',$op->id)->update($stable);
    }
    $key=$ticket($op,'release');
    $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);
    if($kind==='transfer'){$page->assertSee('Use updated account details')->assertDontSee('Release hold');}
    else{$page->assertSee('Release hold');}
    $page->callAction('release')->assertNotified($kind==='transfer'?'Contact details updated':'Hold released');
    $after=$op->fresh();same($kind==='transfer'?['held','transfer_setup_review_required']:['queued',null],[$after->state,$after->safe_code],'Design recovery state: '.$kind);
    same('+1.2025550100',$after->payload['contacts']['registrant']['phone'],'Recollect owner, never staff contacts');
    same(false,$after->payload['setup_required'],'Recollected contacts are ready');
    same([$staff->id,$user->id,$kind==='transfer'?'Use updated account details':'Release hold','contact_details_required'],array_values(array_intersect_key($after->policy['contact_readmissions'][0],array_flip(['actor_id','owner_id','reason','previous_code']))),'Bound contact audit');
    same(['release'],array_column($after->policy['staff_actions'],'action'),'Staff review audit records recovery');
    $history=StaffReviewHistory::rows($after);
    same($kind==='transfer'?'Contact details updated':'Hold released',$history[0]['event'],'History names the actual recovery');
    same(null,$history[0]['sub'],'History does not repeat the action label');
    same($deadline,TransferInputs::deadline($after,$clock->now())?->format(DATE_ATOM),'Original transfer deadline retained');
    foreach(['epp','registry','registry_schema','nameservers','privacy'] as $field){same($op->payload[$field]??null,$after->payload[$field]??null,'Independent frozen input retained: '.$field);}
    foreach(['attempt','funding','cost','started_at','request_fingerprint','completed_at'] as $field){same($op->$field,$after->$field,'Recovery preserves evidence: '.$field);}
    same($money,nativeFinancialSnapshot($op),'Recovery changes no money, service or reservation');
    commerceReject(fn()=>$run($after,'release',$key));
    if($kind==='transfer'){$run($after,'release');same('queued',$op->fresh()->state,'Transfer needs a separate reviewed release');}
    $run($op->fresh(),'hold');$run($op->fresh(),'release');same('queued',$op->fresh()->state,'A later ordinary hold can release');
}

// Late contact correction keeps the original code cutoff and the separate transfer review hold.
$user->properties()->where('key','phone')->update(['value'=>'']);
$late=$new('staff-late-contact-transfer-placeholder.com',true,true,'transfer');ContactRecovery::hold($late->id);$late=$late->fresh();
$deadline=TransferInputs::deadline($late,$clock->now());expect($deadline!==null,'Late fixture has its original paid deadline');
$policy=$late->policy;$code=$late->payload['epp'];$money=nativeFinancialSnapshot($late);
$user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);
$clock->instant=$deadline->modify('+1 second');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$late->id])->assertActionVisible('release')
    ->assertSee('Use updated account details')->assertDontSee('Release hold')->callAction('release')->assertNotified('Contact details updated');
same(['held','transfer_setup_review_required'],[$late->fresh()->state,$late->fresh()->safe_code],'Late correction cannot queue a transfer');
same($deadline->format(DATE_ATOM),TransferInputs::deadline($late->fresh(),$clock->now())?->format(DATE_ATOM),'Late recovery never resets deadline');
same([$policy['paid_at'],$policy['code_saved_at'],$code],[$late->fresh()->policy['paid_at'],$late->fresh()->policy['code_saved_at'],$late->fresh()->payload['epp']],'Original paid and code evidence retained');
same($money,nativeFinancialSnapshot($late),'Late correction preserves native money and reservation');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$late->id])->assertActionHidden('release')->assertDontSee('Release hold');
commerceReject(fn()=>$run($late->fresh(),'release'));
same(0,$state->writes,'Contact recovery never sends');
echo "Staff contact recovery passed\n";
$nativeSuccessful=true;
