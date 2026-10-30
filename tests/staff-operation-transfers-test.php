<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/transfer-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations,StaffOperationWrites,TransferInputs};
staffPanel();app()->instance(Clock::class,$clock);app()->instance(Refunds::class,$refunds);
$perms=[Staff::VIEW,'admin.domains.manage','admin.invoices.update'];
foreach(array_keys(StaffOperationReview::ACTIONS)as$action){$perms[]=StaffOperationReview::permission($action);}
$staff=staffUser($perms);$service=new StaffOperations($clock);
$run=static function($op,$action)use($service,$staff,$clock){$op=$op->fresh();$key=StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,$action,StaffOperationReview::stamp($op,$op->domain),$clock);
    $service->run($staff,$op->domain_id,$op->id,$action,$key,'Reviewed exact transfer evidence');};
$op=$newTransfer(placeholderComFixtureName('staff-saved-transfer-placeholder.com'));$op->update(['state'=>'held','safe_code'=>'staff_review_required']);
// Obvious state/action refusals do not load a holding snapshot. The locked writer still refuses them.
foreach([['queued','register'],['rejected','transfer'],['succeeded','renew'],['held','contacts']] as [$savedState,$savedAction]){
    $candidate=$op->fresh();$candidate->state=$savedState;$candidate->action=$savedAction;
    Illuminate\Support\Facades\DB::enableQueryLog();Illuminate\Support\Facades\DB::flushQueryLog();
    $allowed=StaffOperationWrites::canRelease($candidate,$clock->now());$reads=Illuminate\Support\Facades\DB::getQueryLog();Illuminate\Support\Facades\DB::disableQueryLog();
    same(false,$allowed,'Non-held or unsupported request cannot be released');same([],$reads,'State/action prefilter avoids holding-fact reads');
    commerceReject(fn()=>StaffOperationWrites::requireRelease($candidate,[],$clock->now()));
}
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('release')->assertNotified('Hold released');
same('queued',$op->fresh()->state,'Saved-code held transfer may queue only before cutoff');
$run($op,'hold');$clock->instant=$now->modify('+30 days');commerceReject(fn()=>$run($op,'release'));
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('refund',['reason'=>'Close proven unsent saved-code transfer'])->assertNotified('Cancelled, credit added');
same('account_credit',$op->fresh()->funding['refund']['to'],'Exact saved-code credit');same('definitely_unsent',$op->fresh()->attempt['send_state'],'Only virgin evidence creates local negative proof');
$clock->instant=$now;
foreach(['transfer_not_registered','staff_review_required']as$reason){
    $unpaid=$reason==='staff_review_required';$row=$newTransfer(placeholderComFixtureName('staff-transfer-'.($unpaid?'unpaid':'404').'-placeholder.com'),0,!$unpaid);
    $row->update(['state'=>'held','safe_code'=>$reason]);$before=[$row->fresh()->state,$row->fresh()->attempt,$row->fresh()->funding,$row->domain->fresh()->reservation_name];
    foreach(['release','refund','credit','disposition']as$action){commerceReject(fn()=>$run($row,$action));}
    same($before,[$row->fresh()->state,$row->fresh()->attempt,$row->fresh()->funding,$row->domain->fresh()->reservation_name],'404 without fresh proof and never-paid holds retained');
}
// A real paid request can be held while still awaiting its owner's code.
// Raw malformed rows cover retained input evidence that the current input boundary would refuse.
foreach(['missing_code','invalid_code','missing_paid_at','invalid_paid_at','expired_deadline'] as $fault){
    $row=$newTransfer(placeholderComFixtureName('staff-transfer-'.str_replace('_','-',$fault).'-placeholder.com'),0,true,$fault!=='missing_code');
    if($fault==='missing_code'){same('awaiting_code',$row->state,'Paid transfer genuinely awaits owner code');}
    $run($row,'hold');$row=$row->fresh();
    if(in_array($fault,['invalid_code','missing_paid_at','invalid_paid_at'],true)){
        $payload=$row->payload;$policy=$row->policy;
        if($fault==='invalid_code'){$payload['epp']="\n";}
        elseif($fault==='missing_paid_at'){unset($policy['paid_at']);}
        else{$policy['paid_at']='not-a-time';}
        $row->setAttribute('payload',$payload);$row->setAttribute('policy',$policy);
        Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$row->id)->update(array_intersect_key($row->getAttributes(),array_flip(['payload','policy'])));
        $row=$row->fresh();
    }
    if($fault==='expired_deadline'){$clock->instant=TransferInputs::deadline($row,$clock->now());}
    $before=$row->getRawOriginal();commerceReject(fn()=>$run($row,'release'));
    same($before,$row->fresh()->getRawOriginal(),'Invalid transfer release leaves evidence intact: '.$fault);
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release')->assertDontSee('Release hold');
    expect((RequestFacts::notice($row)['action']??null)!=='release','Invalid transfer notice has no release: '.$fault);
    same(false,OperationActions::relevant('release',$row),'Invalid transfer page has no release: '.$fault);
    same(false,StaffOperationWrites::canRelease($row,$clock->now()),'Transfer release eligibility requires saved inputs: '.$fault);
    $clock->instant=$now;
}
same([], $state->calls,'Staff never sends transfer or registrar refund');same(0,$state->reads,'Pages and local actions never read registrar');
echo "Native saved-code, cutoff, missing fresh404 and never-paid refusals passed.\n";$nativeSuccessful=true;
