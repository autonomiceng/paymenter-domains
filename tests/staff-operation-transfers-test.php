<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations};
staffPanel();app()->instance(Clock::class,$clock);app()->instance(Refunds::class,$refunds);
$perms=[Staff::VIEW,'admin.domains.manage','admin.invoices.update'];
foreach(array_keys(StaffOperationReview::ACTIONS)as$action){$perms[]=StaffOperationReview::permission($action);}
$staff=staffUser($perms);$service=new StaffOperations($clock);
$run=static function($op,$action)use($service,$staff,$clock){$op=$op->fresh();$key=StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,$action,StaffOperationReview::stamp($op,$op->domain),$clock);
    $service->run($staff,$op->domain_id,$op->id,$action,$key,'Reviewed exact transfer evidence');};
$op=$newTransfer('staff-saved-transfer.com');$op->update(['state'=>'held','safe_code'=>'staff_review_required']);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('release',['reason'=>'Saved code and exact payment reviewed'])->assertNotified('Review recorded');
same('queued',$op->fresh()->state,'Saved-code held transfer may queue only before cutoff');
$run($op,'hold');$clock->instant=$now->modify('+30 days');commerceReject(fn()=>$run($op,'release'));
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('refund',['reason'=>'Close proven unsent saved-code transfer'])->assertNotified('Review recorded');
same('account_credit',$op->fresh()->funding['refund']['to'],'Exact saved-code credit');same('definitely_unsent',$op->fresh()->attempt['send_state'],'Only virgin evidence creates local negative proof');
$clock->instant=$now;
foreach(['transfer_not_registered','staff_review_required']as$reason){
    $unpaid=$reason==='staff_review_required';$row=$newTransfer('staff-transfer-'.($unpaid?'unpaid':'404').'.com',0,!$unpaid);
    $row->update(['state'=>'held','safe_code'=>$reason]);$before=[$row->fresh()->state,$row->fresh()->attempt,$row->fresh()->funding,$row->domain->fresh()->reservation_name];
    foreach(['release','refund','credit','disposition']as$action){commerceReject(fn()=>$run($row,$action));}
    same($before,[$row->fresh()->state,$row->fresh()->attempt,$row->fresh()->funding,$row->domain->fresh()->reservation_name],'404 without fresh proof and never-paid holds retained');
}
same([], $state->calls,'Staff never sends transfer or registrar refund');same(0,$state->reads,'Pages and local actions never read registrar');
echo "Native saved-code, cutoff, missing fresh404 and never-paid refusals passed.\n";$nativeSuccessful=true;
