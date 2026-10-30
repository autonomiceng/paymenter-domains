<?php
// Native paid request and actual staff review actions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory;
$op=$new(placeholderComFixtureName('staff-hold-release-placeholder.com'));
$money=$nativeMoney();
foreach(['Customer asked to wait','Customer confirmed the next attempt'] as $cycle=>$reason){
    $service->run($staff,$op->domain_id,$op->id,'hold',$ticket($op->fresh(),'hold'),$reason);
    same('held',$op->fresh()->state,'Native request is held before page release');
    $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);
    $page->assertActionVisible('release')->mountAction('release')->assertActionNotMounted('release')
        ->assertHasNoActionErrors()->assertNotified('Hold released')->assertDontSee('Reason');
    $page->assertDontSee('Put on hold by staff.')->assertDontSee('On hold')
        ->assertSee('In progress')->assertSee('Hold released')->assertActionHidden('release')->assertActionVisible('hold');
    same('queued',$op->fresh()->state,'Release runs immediately without a reason modal');
    $audit=$op->fresh()->policy['staff_actions'];
    same(2*($cycle+1),count($audit),'Exactly one completed audit entry for each action');
    same(array_fill(0,count($audit),'completed'),array_column($audit,'phase'),'No provisional audit phases');
    same(array_merge(...array_fill(0,$cycle+1,['hold','release'])),array_column($audit,'action'),'Exact action order');
    same(array_fill(0,count($audit),$staff->id),array_column($audit,'actor_id'),'Every audit belongs to the acting staff member');
}
$audit=$op->fresh()->policy['staff_actions'];
same(['Customer asked to wait','Release hold','Customer confirmed the next attempt','Release hold'],array_column($audit,'reason'),'Exact completed reasons across two cycles');
same(['Put on hold','Hold released','Put on hold','Hold released'],array_column(StaffReviewHistory::rows($op->fresh()),'event'),'Exact visible audit events');
same(['Customer asked to wait',null,'Customer confirmed the next attempt',null],array_column(StaffReviewHistory::rows($op->fresh()),'sub'),'Only entered reasons appear below events');
// Keep the same browser component through successive writes: no remount or refresh between actions.
foreach([1,2] as $cycle){
    $page->callAction('hold')->assertNotified('Request on hold')->assertSee('On hold')->assertSee('Put on hold by staff.')
        ->assertActionVisible('release')->assertActionHidden('hold');
    $page->callAction('release')->assertNotified('Hold released')->assertSee('In progress')->assertSee('Hold released')
        ->assertDontSee('On hold')->assertDontSee('Put on hold by staff.')->assertActionVisible('hold')->assertActionHidden('release');
    same(4+2*$cycle,count($op->fresh()->policy['staff_actions']),'Each same-page action gets a current one-use review ticket');
}
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSee('History')
    ->assertSee('Customer asked to wait')->assertSee('Customer confirmed the next attempt')->assertSee('Hold released');
$history=Livewire::actingAs($staff)->test(RequestsRelationManager::class,['ownerRecord'=>$op->domain->fresh(),'pageClass'=>ViewDomain::class]);
$history->assertSee('History')->assertSee('Request #'.$op->id);
$dom=new DOMDocument;@$dom->loadHTML($history->html());
$links=(new DOMXPath($dom))->query('//a[contains(normalize-space(.),"Request #'.$op->id.' ")]');
expect($links->length>0,'History renders an actual request link');
$url=OperationResource::getUrl('view',['record'=>$op->id]);
foreach($links as $link){same($url,$link->getAttribute('href'),'History links to this exact native request');}
same($money,$nativeMoney(),'Hold and release retain all financial and queue rows');
echo "Native Hold and Release: exact completed audit reasons, repeat cycles, no modal and History request link passed.\n";
$nativeSuccessful=true;
