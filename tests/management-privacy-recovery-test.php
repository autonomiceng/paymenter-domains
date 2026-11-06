<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic privacy native coverage.
declare(strict_types=1);
require __DIR__.'/Support/management-privacy.php';
use App\Models\{Invoice,InvoiceItem,InvoiceTransaction,Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Services\{ManagementEvidence,Refunds};
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-unsent-placeholder.com'));$state->privacy=false;$op=$submit($d,$driver,'privacy_purchase');$pay($op);$state->cost='unknown';same('held',$management->run($op->id,$driver)->state,'Unknown cost never treated as zero');

same(['amount'=>$op->amount,'currency'=>'USD'],$refunds->refundHeld($staff,$op->id,'privacy_unavailable'),'Definitely unsent paid privacy uses slice26 account credit');same(null,$refunds->creditRejected($op->id),'Privacy refund once');same('active',$d->service->fresh()->status,'Refund preserves hosting of acquired domain');
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-retire-placeholder.com'));$state->privacy=false;$state->cost='8.00';$op=$submit($d,$driver,'privacy_purchase');same(true,ManagementEvidence::resolved($op,$d),'Unpaid unsent privacy never blocks renewal');$calls=count($state->calls);same('rejected',$management->retire($user,$op->id)->state,'Unpaid privacy safely retired');same('cancelled',Invoice::find($op->invoice_id)->status,'Unpaid invoice cancelled');same($op->id,$management->retire($user,$op->id)->id,'Retirement idempotent');same($calls,count($state->calls),'Retirement no provider I/O');
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-slot-placeholder.com'));$state->privacy=false;$state->cost='unknown';$op=$submit($d,$driver,'privacy_purchase');$pay($op);$state->cost='8.00';Cache::put('domains-write-slot:'.$op->account_fingerprint,true,25);same('awaiting_payment',$management->run($op->id,$driver)->state,'Busy slot defers before claim');same([],$op->fresh()->attempt??[],'Preclaim slot has no attempt');
// Extend the real consume-slot race: native action visibility must agree with the unchanged writer.
staffPanel();$role->update(['permissions'=>[...$role->permissions,\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::permission('hold')]]);
\Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$op->id])->assertActionVisible('hold');
Cache::flush();$state->during=static fn()=>Cache::put('domains-write-slot:'.$op->account_fingerprint,true,25);same('queued',$management->run($op->id,$driver)->state,'Consume slot race remains retryable unsent');same('absent',$op->fresh()->attempt['seal'],'Temporary consume rejection never seals');
$op=$op->fresh();$attempt=$op->attempt;$calls=count($state->calls);
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\StaffOperationWrites::hold($op,[]));
\Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$op->id])
    ->assertActionHidden('hold')->assertSee('This change has already started and cannot be put on hold. It will retry automatically.')->assertDontSee('Go to Orders switch');
same($attempt,$op->fresh()->attempt,'Reading deferred privacy retains its attempt');same($calls,count($state->calls),'Reading deferred privacy makes no registrar calls');
Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Deferred consume retries before first send');same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='PurchaseServices')),'Exactly one privacy write after deferral');

$nativeSuccessful=true;echo "management-privacy-recovery-test.php passed\n";
