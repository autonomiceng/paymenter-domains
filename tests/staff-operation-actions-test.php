<?php
// Native staff workflow boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/staff-operation-fixture.php';
use App\Models\{Credit,InvoiceTransaction};
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Filament\Notifications\Notification;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema,ObservationWork,Refunds,RenewalReplacements,StaffOperationReview,StaffOperations};
$op=$held('staff-actions-placeholder.com'); $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful();
// Every action has an independent default-deny permission; a general manager has none of them.
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){
    $staff->role->update(['permissions'=>array_values(array_diff($all,[StaffOperationReview::permission($action)]))]);
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden($action);
    commerceReject(fn()=>$run($op,$action));
}
$staff->role->update(['permissions'=>array_values(array_diff($all,[Staff::VIEW]))]);
commerceReject(fn()=>$run($op,'refund')); $staff->role->update(['permissions'=>$all]);
// Native modal reason validation and allowed release, audit, immutable attempt and single-use key.
$page->mountAction('release')->setActionData(['reason'=>'no'])->callMountedAction()->assertHasActionErrors(['reason'=>'min']);
$page->unmountAction()->mountAction('release')->setActionData(['reason'=>"Review\nwith newline"])->callMountedAction()->assertHasActionErrors(['reason'=>'regex']);
$key=$ticket($op,'release'); $before=$op->attempt; $run($op,'release',$key);
same('queued',$op->fresh()->state,'Unattempted hold queues through explicit writer'); same($before,$op->fresh()->attempt,'No attempt reset');
$audit=$op->fresh()->policy['staff_actions'][0]; same($staff->id,$audit['actor_id'],'Current staff audited');
same('held',$audit['before']['state'],'Before retained');same('queued',$audit['after']['state'],'After retained');
commerceReject(fn()=>$run($op,'release',$key));
foreach([array_diff_key($op->fresh()->policy,['staff_actions'=>true]),[...$op->fresh()->policy,'staff_actions'=>[]]] as $changed){
    commerceReject(fn()=>$op->fresh()->update(['policy'=>$changed]));
}
echo "Release guard passed.\n";
// Authorized native hold and refund confirmations invoke only the engine refund path.
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('hold',['reason'=>'Pause for customer review'])->assertNotified('Review recorded');
echo "Native hold passed.\n"; same('held',$op->fresh()->state,'Native hold action'); $before=$credit();
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionVisible('refund')->callAction('refund',['reason'=>'Customer cancelled this unsent order'])->assertHasNoActionErrors()->assertNotified('Review recorded');
echo "Native refund passed.\n";
same('rejected',$op->fresh()->state,'Native refund uses engine disposition');expect($credit()>$before,'Exact engine account credit applied');
same('account_credit',$op->fresh()->funding['refund']['to'],'Engine once marker');
Paymenter\Extensions\Servers\Domains\Services\DomainReservations::releaseTerminal($op->domain->domain);
same(null,$op->domain->fresh()->reservation_name,'Proven unsent registration releases only after committed credit');
// A failed native credit keeps the audited rejection; the separately permitted UI retry succeeds once.
$retry=$held('staff-credit-retry-placeholder.com'); $before=$credit();
DB::unprepared("CREATE TRIGGER staff_credit_fail BEFORE UPDATE ON credits BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$retry->id])->assertActionVisible('refund')->callAction('refund',['reason'=>'Customer cancelled unsent order'])->assertHasNoActionErrors()->assertNotified(Notification::make()->danger()->title('Review refused')->body('Reload this page and review current permissions, funding and retained evidence. Reference: refund/'.$retry->id.'/database_failure. A credit decision may be retained; check the ledger before retrying.'));
echo "Failed credit retained.\n";
DB::unprepared('DROP TRIGGER staff_credit_fail'); same('rejected',$retry->fresh()->state,'Decision survives failed credit');same($before,$credit(),'Failed credit atomic');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$retry->id])->assertActionVisible('credit')->callAction('credit',['reason'=>'Retry verified retained credit'])->assertHasNoActionErrors()->assertNotified('Review recorded');
echo "Native credit passed.\n";
$after=$credit();commerceReject(fn()=>$run($retry->fresh(),'credit'));same($after,$credit(),'Repeat credit never duplicates');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$retry->id])->callAction('disposition',['reason'=>'Reviewed retained terminal proof'])->assertNotified('Review recorded');
echo "Native disposition passed.\n";
// Open page and old tickets cannot act after state changes, expiration, domain spoofing or role revocation.
$stale=$held('staff-stale-placeholder.com');$open=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$stale->id]);$key=$ticket($stale,'release');
$stale->update(['safe_code'=>'funding']);$open->callAction('release',['reason'=>'Stale review cannot resume'])->assertNotified(Notification::make()->danger()->title('Review refused')->body('This request changed. Reload this page.'));
commerceReject(fn()=>$run($stale,'release',$key));
$foreign=$held('staff-foreign-placeholder.com');$key=$ticket($stale->fresh(),'refund');
commerceReject(fn()=>$service->run($staff,$foreign->domain_id,$foreign->id,'refund',$key,'Cross domain review refused'));
$key=$ticket($foreign,'refund');$clock->instant=$clock->now()->modify('+901 seconds');commerceReject(fn()=>$run($foreign,'refund',$key));
$key=$ticket($foreign,'refund');$staff->role->update(['permissions'=>[Staff::VIEW]]);commerceReject(fn()=>$run($foreign,'refund',$key));$staff->role->update(['permissions'=>$all]);
// Every UI entry point preserves sent/null/unknown financial evidence, reservation and customer credit.
foreach(['uncertain','pending','held','rejected'] as $stateName){
    [$sent]=$prepare(placeholderComFixtureName('staff-sent-'.$stateName.'-placeholder.com'),'null');$sent->update(['state'=>$stateName,'safe_code'=>$stateName==='held'?'transfer_needs_action':'transfer_provider_rejected']);
    $before=[$sent->fresh()->attempt,$sent->fresh()->funding,$sent->domain->fresh()->reservation_name,$credit()];
    $view=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$sent->id]);
    foreach(['hold','release','refund','credit','disposition','replacement'] as $action){$view->assertActionHidden($action);commerceReject(fn()=>$run($sent->fresh(),$action));}
    same($before,[$sent->fresh()->attempt,$sent->fresh()->funding,$sent->domain->fresh()->reservation_name,$credit()],'No UI action releases, credits or erases unknown/sent evidence');
}
// Read actions leave ten audit entries available for financial/disposition recovery.
$headroom=$held('staff-audit-headroom-placeholder.com');$history=array_fill(0,90,['key'=>'synthetic-prior']);$headroom->update(['policy'=>[...$headroom->policy,'staff_actions'=>$history]]);
commerceReject(fn()=>$run($headroom->fresh(),'recheck'));$run($headroom->fresh(),'release');same(91,count($headroom->fresh()->policy['staff_actions']),'Read cap preserves local recovery headroom');
echo "Native financial review and refusal boundaries passed.\n";
$nativeSuccessful=true;
