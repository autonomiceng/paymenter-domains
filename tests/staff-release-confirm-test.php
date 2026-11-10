<?php
// Every Release hold asks first and says what it starts. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Services\EnomTestRegistration;
$release=static fn($op)=>collect(OperationActions::make($op->fresh(),'release-confirm-stamp'))->first(fn($a)=>$a->getName()==='release');
$op=$held('staff-release-confirm-placeholder.com');
$account=RequestFacts::account($op)['account'];$original=(bool)$account->processing_enabled;
expect(!EnomTestRegistration::releaseSends($op,$account),'This release queues the request instead of sending it to Enom at once');
foreach([true,false] as $processing){
    DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>$processing]);
    $action=$release($op);
    expect($action->isConfirmationRequired(),'Release hold asks before it starts, with Orders '.($processing?'on':'off'));
    $words=(string)$action->getModalDescription();
    expect(str_contains($words,'registration'),'The dialog names what release starts: '.$words);
    expect($processing===str_contains($words,'Once sent, it can\'t be taken back.'),'Only a release that queues a send warns it cannot be taken back: '.$words);
}
// The confirmed action still releases the hold.
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>$original]);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('release')->assertHasNoActionErrors()->assertNotified('Hold released');
expect($op->fresh()->state!=='held','Confirmed release leaves the hold');
echo "Every Release hold confirms first.\n";
$nativeSuccessful=true;
