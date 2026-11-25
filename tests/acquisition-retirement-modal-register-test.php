<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Services\AcquisitionRetirement;
// Match the installed after-commit wake used by the original modal scenarios.
AcquisitionRetirement::boot();
// Real staff modal success and refusal use the same locked authority. The panel and its routes are registered
// once, before the checkpoint; each case makes its own order and the reset restores rows, auth and listeners.
Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();staffPanel();Auth::login($staff);
$pageClass=Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class;
$fixture=compact('new','staff','pageClass');
$cases['success']=static function()use($fixture):void{extract($fixture);
    $modalOp=$new(placeholderComFixtureName('unpaid-modal-placeholder.com'),paid:false);Auth::login($staff);
    $page=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$modalOp->domain_id])->mountAction('retire_unpaid')->assertActionMounted('retire_unpaid');
    $beforeInvalidReason=$modalOp->fresh()->getRawOriginal();
    submitStaffAction($page, ['reason'=>"Reviewed\u{2028}closure"])->assertHasActionErrors(['reason'=>'regex']);
    same($beforeInvalidReason,$modalOp->fresh()->getRawOriginal(),'Closure reason field error changes no order');
    submitStaffAction($page, ['reason'=>'Retire from real staff modal'])->assertNotified('Order cancelled')->assertRedirect(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$modalOp->domain_id]));
    Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$modalOp->domain_id])->assertActionDoesNotExist('retire_unpaid');same(null,$modalOp->domain->fresh()->reservation_name,'Real modal invokes retirement');
    $paid=$new(placeholderComFixtureName('unpaid-refusal-placeholder.com'));Auth::login($staff);
    Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$paid->domain_id])->assertActionDoesNotExist('retire_unpaid');
    $viewOnly=staffUser(['admin.domains.view']);Livewire\Livewire::actingAs($viewOnly)->test($pageClass,['record'=>$paid->domain_id])->assertActionDoesNotExist('retire_unpaid');
};
// Both unpaid acquisition actions explain registrar evidence and other requests without blaming one cause.
foreach(['register','transfer'] as $action){foreach(['provider','sibling'] as $cause){
$cases[$action.'-'.$cause]=static function()use($fixture,$action,$cause):void{extract($fixture);
    $op=$new(placeholderComFixtureName('unpaid-modal-'.$action.'-'.$cause.'-placeholder.com'),paid:false,action:$action);
    if($cause==='provider'){$op->domain->update(['provider_order_id'=>'synthetic-provider']);}
    else{Operation::create(['domain_id'=>$op->domain_id,'service_id'=>$op->service_id,'server_id'=>$op->server_id,'registrar_key'=>$op->registrar_key,'account_fingerprint'=>$op->account_fingerprint,'authority_generation'=>1,'operation_key'=>'modal-other:'.$op->id,'action'=>'renew','term'=>1,'state'=>'queued','amount'=>'1.00','currency'=>'USD']);}
    $invoice=App\Models\Invoice::findOrFail($op->invoice_id)->getRawOriginal();$reservation=$op->domain->fresh()->reservation_name;
    $page = Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$op->domain_id])->mountAction('retire_unpaid')->assertActionMounted('retire_unpaid');
    submitStaffAction($page, ['reason'=>'Review refused unpaid cancellation'])->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't cancel order")
            ->body("Can't cancel: the order is no longer pending, or it has other requests or registrar details."));
    same($reservation,$op->domain->fresh()->reservation_name,$action.' '.$cause.' keeps the reservation');
    same($invoice,App\Models\Invoice::findOrFail($op->invoice_id)->getRawOriginal(),$action.' '.$cause.' keeps the invoice');
};
}}
nativeFixtureCases($cases, compact('clock', 'retirement', 'staff', 'user'));
echo "Unpaid retirement staff modal cases passed.\n";$nativeSuccessful=true;
