<?php
// An old click says what already happened. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
$open=fn($op)=>Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful();
$force=static fn(Operation $op,array $values)=>Operation::withoutEvents(static fn()=>$op->fresh()->update($values));

// An old click never looks like a new success: it says what already happened and refreshes the page.
$stale=$held(placeholderComFixtureName('truth-stale-placeholder.com'));
$first=$open($stale);$second=$open($stale);
$first->callAction('release')->assertNotified('Hold released');
$audit=count($stale->fresh()->policy['staff_actions']);
$second->call('mountAction','release')->assertNotified('Hold already released')->assertSee('In progress')
    ->assertDontSee('On hold')->assertActionHidden('release')->assertActionVisible('hold');
$first->call('mountAction','release')->assertNotified('Hold already released');
same($audit,count($stale->fresh()->policy['staff_actions']),'An old Release writes nothing');
// That click refreshed the page, so its next action runs from current facts.
$second->callAction('hold')->assertNotified('Request on hold')->assertSee('On hold')->assertActionVisible('release');
same(['held',$audit+1],[$stale->fresh()->state,count($stale->fresh()->policy['staff_actions'])],'Put on hold works on the same page after an old click');
$first->call('mountAction','hold')->assertNotified('Already on hold')->assertSee('On hold')->assertActionHidden('hold');
same($audit+1,count($stale->fresh()->policy['staff_actions']),'An old Put on hold writes nothing');
$same=$open($stale);
$same->callAction('release')->assertNotified('Hold released')->call('mountAction','release')->assertNotified('Hold already released');
same($audit+2,count($stale->fresh()->policy['staff_actions']),'A double click on one page writes once');
echo "Old clicks passed.\n";

$nativeSuccessful=true;
