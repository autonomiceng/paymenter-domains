<?php
// Native staff workflow boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
// Native re-check calls the existing read-only reconciliation engine; actual proof alone can complete.
[$check]=$prepare(placeholderComFixtureName('staff-recheck-placeholder.com'));$calls=count($observation->calls);
$checkedPage=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->callAction('recheck')->assertNotified('Registrar checked')
    ->assertSee('Completed')->assertSee('Registrar checked')->assertDontSee('has not confirmed')->assertDontSee('More')->assertActionHidden('recheck');
same('succeeded',$check->fresh()->state,'Reconciliation engine verifies actual charge and expiry');expect(count($observation->calls)>$calls,'Only explicit action reads');
$history=$check->fresh()->policy['staff_actions'];same('read_finished',end($history)['phase'],'Read result audited');
unset($checkedPage);gc_collect_cycles();
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
// d879bad2 retains a lost reply and the 24-hour staff answer boundary instead of claiming an unavailable read.
[$uncheckable]=$prepare(placeholderComFixtureName('staff-no-reply-placeholder.com'),'null');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$uncheckable->id])->callAction('recheck')
    ->assertNotified('Enom has not answered yet')->assertSee('Ask Enom to check this attempt.')->assertSee('You can record Enom')->assertDontSee('Status checked');
same('awaiting_answer',$uncheckable->fresh()->policy['status_check']['outcome'],'Lost reply retains the registrar answer state');
same((new \DateTimeImmutable($uncheckable->attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp(),$uncheckable->fresh()->policy['status_check']['answer_after'],'Staff answer waits 24 hours from this dispatch');
same([],$observation->calls,'No original ID or accepted history never claims a registrar read');
[$transportFailure]=$prepare(placeholderComFixtureName('staff-failed-read-placeholder.com'),'captured');
$observation->fault='transport';
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$transportFailure->id])->callAction('recheck')
    ->assertNotified('Cannot check registrar')->assertSee('Support can check the original order.');
[$lastPoll]=$prepare(placeholderComFixtureName('staff-last-read-placeholder.com'),'captured');
$policy=$lastPoll->policy;$policy['reconciliation']['polls']=4;$lastPoll->update(['policy'=>$policy]);$observation->fault='wrong-charge';
$reconcile->run($lastPoll->id);
same(null,$lastPoll->fresh()->policy['status_check']['next_at'],'Exhausted reads promise no automatic retry');
echo "Native staff-operation-reads passed.\n";
$nativeSuccessful=true;
