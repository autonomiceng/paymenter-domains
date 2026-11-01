<?php
// A completed request tells one true story. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
$open=fn($op)=>Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful();
$force=static fn(Operation $op,array $values)=>Operation::withoutEvents(static fn()=>$op->fresh()->update($values));

// Unreadable sibling evidence cannot roll back a completion save or close its review alert.
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
$completing=$new(placeholderComFixtureName('unreadable-sibling-placeholder.com'));
$completing->update(['state'=>'pending','policy'=>[...$completing->policy,'reconciliation'=>['staff_alert'=>true,'reason'=>'correction_required']],
    'attempt'=>['corrections'=>['contacts'=>['state'=>'authorization_required']]]]);
$peer=$completing->replicate();$peer->operation_key='unreadable-sibling';$peer->invoice_item_id=null;$peer->state='succeeded';$peer->save();
\Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$peer->id)->update(['policy'=>'unreadable']);
$key=\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($completing->domain,'operation_review',$clock->now()->getTimestamp(),$completing->id);
\Illuminate\Support\Facades\DB::transaction(function()use($completing):void{
    $completing->update(['state'=>'succeeded']);
    $completing->domain->update(['status'=>'active']);
});
same(['succeeded','active'],[$completing->fresh()->state,$completing->domain->fresh()->status],'Unreadable sibling leaves completion committed');
same(null,\Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('alert_key',$key)->value('resolved_at'),'Unreadable sibling keeps the completion alert open');

// A completed request never says it was not sent.
$done=$new(placeholderComFixtureName('truth-done-placeholder.com'));
$force($done,['state'=>'succeeded','safe_code'=>'registration_correction_required','started_at'=>$clock->now(),
    'attempt'=>['corrections'=>['contacts'=>['state'=>'authorization_required','readback_required'=>true]]]]);
$open($done)->assertSee('Completed')->assertSee('Still to fix: contact details')->assertSee('Open domain')->assertDontSee('Not sent');
$force($done,['state'=>'pending','safe_code'=>'registration_evidence_required']);
$open($done)->assertSee('In progress')->assertDontSee('Not sent');
// The registrar accepted it, then the account changed before the purchase finished.
$force($done,['state'=>'uncertain','safe_code'=>'registration_authority_changed',
    'attempt'=>['execution'=>['disposition'=>'accepted_with_discrepancy'],'registration'=>['accepted'=>true,'order_reference'=>'1234']]]);
Livewire::actingAs($staff)->test(ListOperations::class)->assertSuccessful()
    ->assertSee('Sent. Registrar account changed before it finished')->assertDontSee('Not sent: registrar account changed');
echo "Completed story passed.\n";
$nativeSuccessful=true;
