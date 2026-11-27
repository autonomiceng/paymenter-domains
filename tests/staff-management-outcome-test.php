<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Staff close an unconfirmed free change as failed.
declare(strict_types=1);
require __DIR__.'/Support/management-privacy.php';
use Illuminate\Support\Facades\{Cache,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{ManagementEvidence,ObligationAlerts,StaffOperationReview,StaffOperations};
staffPanel();
$role->update(['permissions'=>[...$role->permissions,Staff::VIEW,StaffOperationReview::permission('mark_failed')]]);
$service=new StaffOperations($clock);
$run=static function($op)use($staff,$clock,$service):void{
    $key=StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,'mark_failed',StaffOperationReview::stamp($op->fresh(),$op->domain->fresh()),$clock);
    $service->run($staff,$op->domain_id,$op->id,'mark_failed',$key,'Registrar support found no change');
};
$page=static fn($op)=>Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);
// Reads can't settle an unconfirmed free change, so staff close it as failed from the request page.
[$d,$driver]=$holding(placeholderComFixtureName('outcome-free-placeholder.com'));$state->apply=false;
$op=$submit($d,$driver,'lock',['locked'=>false]);same('uncertain',$management->run($op->id,$driver)->state,'The free change is unconfirmed');
ObligationAlerts::record($d->fresh(),'operation_review',time(),(int)$op->id);
$calls=count($state->calls);$attempt=$op->fresh()->attempt;$observation=$d->fresh()->observation;$note='Enom support found no lock change';
// The notice names Mark as failed only to staff who may use it.
$notice=static function($page):string{$dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$page->html());
    return implode(' ',array_map(fn($n)=>$n->textContent,iterator_to_array((new DOMXPath($dom))->query('//*[@data-domains-row="notice"]'))));};
$label=StaffOperationReview::ACTIONS['mark_failed'];
expect(str_contains($notice($page($op)),$label),'The notice points permitted staff to Mark as failed');
$viewer=staffUser(array_values(array_diff($role->permissions,[StaffOperationReview::permission('mark_failed')])));
$plain=Livewire::actingAs($viewer)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('mark_failed');
expect(!str_contains($notice($plain),$label)&&$notice($plain)!=='','Staff who can\'t mark it failed are not pointed to it');
$page($op)->assertActionVisible('mark_failed')
    ->callAction('mark_failed',['reason'=>'no'])->assertHasActionErrors(['reason'=>'min'])->unmountAction()
    ->callAction('mark_failed',['reason'=>$note])->assertHasNoActionErrors()->assertSee($note)->assertActionHidden('mark_failed');
$after=$op->fresh();
same(['rejected',ManagementEvidence::STAFF_FAILED],[$after->state,$after->safe_code],'Staff record a final failed result');
same($calls,count($state->calls),'Marking as failed sends nothing to the registrar');
foreach($attempt as $field=>$value){same($value,$after->attempt[$field],'The original send evidence is kept');}
same($observation,$d->fresh()->observation,'The domain keeps what normal reads saved');
$history=$after->policy['staff_actions'];$audit=end($history);
same(['mark_failed','completed',$staff->id,$note],[$audit['action'],$audit['phase'],$audit['actor_id'],$audit['reason']],'History keeps who and the note');
expect(is_string($audit['at'])&&$audit['at']!=='','History keeps when');
same(0,DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->whereNull('resolved_at')->count(),'The request alert resolves');
same(true,ManagementEvidence::resolved($after,$d->fresh()),'The closed change no longer blocks new changes');
commerceReject(fn()=>$run($after));
echo "Free unconfirmed change marked as failed.\n";
// A queued change has not gone out, and one being sent is still in flight.
$queued=$submit($d->fresh(),$driver,'lock',['locked'=>true]);same('queued',$queued->state,'A new change can follow');
$page($queued)->assertActionHidden('mark_failed');commerceReject(fn()=>$run($queued));
[$busyDomain,$busyDriver]=$holding(placeholderComFixtureName('outcome-busy-placeholder.com'));
$state->apply=false;Cache::flush();$busy=$submit($busyDomain,$busyDriver,'lock',['locked'=>false]);same('uncertain',$management->run($busy->id,$busyDriver)->state,'A second unconfirmed change');
DB::table('domains_operations')->where('id',$busy->id)->update(['state'=>'running']);
$page($busy)->assertActionHidden('mark_failed');commerceReject(fn()=>$run($busy));same('running',$busy->fresh()->state,'A change being sent is left alone');
// A confirmed change is already settled.
$state->apply=true;[$doneDomain,$doneDriver]=$holding(placeholderComFixtureName('outcome-done-placeholder.com'));
Cache::flush();$done=$submit($doneDomain,$doneDriver,'lock',['locked'=>false]);same('succeeded',$management->run($done->id,$doneDriver)->state,'A confirmed change');
$page($done)->assertActionHidden('mark_failed');commerceReject(fn()=>$run($done));
// A paid change keeps the registrar support answer instead.
[$paidDomain,$paidDriver]=$holding(placeholderComFixtureName('outcome-paid-placeholder.com'));$state->apply=false;$state->privacy=false;
Cache::flush();$paid=$submit($paidDomain,$paidDriver,'privacy_purchase');$pay($paid);same('uncertain',$management->run($paid->id,$paidDriver)->state,'An unconfirmed paid change');
$page($paid)->assertActionHidden('mark_failed');commerceReject(fn()=>$run($paid));same('uncertain',$paid->fresh()->state,'A paid change stays under review');
echo "Paid, settled and in-flight changes refused.\n";
$nativeSuccessful=true;echo "staff-management-outcome-test.php passed\n";
