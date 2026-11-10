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
$all[]=Staff::COSTS;$staff->role->update(['permissions'=>$all]);
$op=$held('staff-actions-placeholder.com'); $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful();
// Every action has an independent default-deny permission; a general manager has none of them.
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){
    $staff->role->update(['permissions'=>array_values(array_diff($all,[StaffOperationReview::permission($action)]))]);
    // Resume sync sits on the domain page; the other actions sit on the request page.
    Livewire::actingAs($staff)->test(...($action==='resume_observation'?[\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$op->domain_id]]:[ViewOperation::class,['record'=>$op->id]]))->assertActionHidden($action);
    commerceReject(fn()=>$run($op,$action));
}
$staff->role->update(['permissions'=>array_values(array_diff($all,[Staff::VIEW]))]);
commerceReject(fn()=>$run($op,'refund')); $staff->role->update(['permissions'=>$all]);
// Native modal reason validation on a money action, then allowed release, audit, immutable attempt and single-use key.
$page->mountAction('refund')->setActionData(['reason'=>'no'])->callMountedAction()->assertHasActionErrors(['reason'=>'min']);
$page->unmountAction()->mountAction('refund')->setActionData(['reason'=>"Review\nwith newline"])->callMountedAction()->assertHasActionErrors(['reason'=>'regex']);
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
$holdText='Put on hold by '.trim($staff->first_name.' '.$staff->last_name);
$actions=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('hold')->assertNotified('Request on hold')
    ->assertSee('On hold')->assertSee($holdText)->assertActionHidden('hold')->assertActionVisible('release');
echo "Native hold passed.\n"; same('held',$op->fresh()->state,'Native hold action'); $before=$credit();
$actions->assertActionVisible('refund')->callAction('refund',['reason'=>'Customer cancelled this unsent order'])->assertHasNoActionErrors()->assertNotified('Request cancelled')
    ->assertSee('Cancelled')->assertSee('Credit added')->assertDontSee('On hold')->assertDontSee($holdText)
    ->assertActionHidden('release')->assertActionHidden('refund')->assertActionHidden('disposition');
echo "Native refund passed.\n";
same('rejected',$op->fresh()->state,'Native refund uses engine disposition');expect($credit()>$before,'Exact engine account credit applied');
same('account_credit',$op->fresh()->funding['refund']['to'],'Engine once marker');
Paymenter\Extensions\Servers\Domains\Services\DomainReservations::releaseTerminal($op->domain->domain);
same(null,$op->domain->fresh()->reservation_name,'Proven unsent registration releases only after committed credit');
// A failed native credit keeps the audited rejection; the separately permitted UI retry succeeds once.
$retry=$held('staff-credit-retry-placeholder.com'); $before=$credit();
DB::unprepared("CREATE TRIGGER staff_credit_fail BEFORE UPDATE ON credits BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
$retryPage=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$retry->id])->assertActionVisible('refund')->callAction('refund',['reason'=>'Customer cancelled unsent order'])->assertHasNoActionErrors()->assertNotified(Notification::make()->warning()->title('Cancelled, refund not finished')->body("The order and its service are cancelled. The refund hasn't gone through (ref database_failure). Use Retry refund. It reuses this refund, so the customer is never refunded twice."))
    ->assertSee('Cancelled')->assertSee('No credit added')->assertDontSee('Cancelled, credit added')->assertDontSee('On hold')->assertDontSee($holdText)
    ->assertActionHidden('refund')->assertActionHidden('release')->assertActionVisible('credit');
same(App\Models\Service::STATUS_CANCELLED,App\Models\Service::findOrFail($retry->service_id)->status,'The service is cancelled before the credit');
echo "Failed credit retained.\n";
DB::unprepared('DROP TRIGGER staff_credit_fail'); same('rejected',$retry->fresh()->state,'Decision survives failed credit');same($before,$credit(),'Failed credit atomic');
$retryPage->assertActionVisible('credit')->callAction('credit',['reason'=>'Retry verified retained credit'])->assertHasNoActionErrors()->assertNotified('Refund checked')
    ->assertSee('Credit added')->assertSee('Refund checked')->assertDontSee('No credit added');
echo "Native credit passed.\n";
$after=$credit();commerceReject(fn()=>$run($retry->fresh(),'credit'));same($after,$credit(),'Repeat credit never duplicates');
// Close request is only for a failed request nobody has closed yet.
$failed=$new(placeholderComFixtureName('staff-close-placeholder.com'));[$claimed]=$claims->claim($failed->id);$claims->finish($claimed);
$failed=Paymenter\Extensions\Servers\Domains\Services\OperationTerminalProof::encode($claims,$failed->id);
// The rehearsal's registration retry 99 bypassed events; the real creation boundary refuses that shape.
$copy=$failed->replicate();$copy->operation_key='synthetic-invalid-close-copy';$copy->retry_number=99;
commerceReject(fn()=>$copy->save());
$unchanged=[$nativeMoney(),$failed->attempt,$failed->domain->reservation_name];
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$failed->id])->callAction('disposition')->assertNotified('Request closed')->assertSee('Request closed')->assertActionHidden('disposition');
same($unchanged,[$nativeMoney(),$failed->fresh()->attempt,$failed->domain->fresh()->reservation_name],'Closing real unsent proof changes no money, send evidence or reservation');
echo "Native disposition passed.\n";
// Open page and old tickets cannot act after state changes, expiration, domain spoofing or role revocation.
$stale=$held('staff-stale-placeholder.com');$open=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$stale->id]);$key=$ticket($stale,'release');
$stale->update(['safe_code'=>'funding']);$open->call('mountAction','release')->assertNotified(Notification::make()->warning()->title('This request changed')->body('Nothing new was done. This page is now up to date.'));
$open->assertSee('Not sent: low registrar balance')->assertDontSee($holdText);
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
// A saved gateway return still consumes current staff permission and a one-use review ticket before polling.
require __DIR__.'/Support/money-receipt.php';MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='pending';
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic receipt','type'=>'gateway','enabled'=>true]);
$returning=$held('staff-gateway-return-placeholder.com');
DB::table('invoice_transactions')->where('invoice_id',$returning->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'receipt_staff_synthetic']);
$run($returning,'refund');$key=$ticket($returning->fresh(),'credit');
$staff->role->update(['permissions'=>array_values(array_diff($all,[StaffOperationReview::permission('credit')]))]);
commerceReject(fn()=>$run($returning->fresh(),'credit',$key));
$staff->role->update(['permissions'=>$all]);$run($returning->fresh(),'credit',$key);
commerceReject(fn()=>$run($returning->fresh(),'credit',$key));
same(1,MoneyReceiptGateway::$sends,'Reviewed recovery does not reserve a second return');
same(2,count($returning->fresh()->policy['staff_actions']),'Refund and authorized poll each retain one audit entry');
echo "Native financial review and refusal boundaries passed.\n";
$nativeSuccessful=true;
