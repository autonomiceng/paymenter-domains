<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
// Staff action, fresh identity/permission/reason snapshot and retained audit.
$staffOp=$new('unpaid-staff.com',paid:false);$key=$ticket($staffOp);
foreach([str_repeat('é',4),str_repeat('é',501),"valid\xff"] as $bad){commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$ticket($staffOp),$bad));}
commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,"Bad\nreason"));
$staffOp->domain->service->forceFill(['label'=>'Changed after review'])->save();commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,'Stale review refuses'));
$key=$ticket($staffOp);$staff->role->update(['permissions'=>['admin.domains.view']]);commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,'Revoked staff refuses'));$staff->role->update(['permissions'=>$permissions]);
$key=$ticket($staffOp);$other=staffUser($permissions);commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$other,$key,'Wrong actor refuses'));
$retirement->retire($staffOp->domain_id,$staff,$ticket($staffOp),str_repeat('🦦',500));
same(null,$staffOp->domain->fresh()->reservation_name,'Staff action releases unpaid name');same('cancelled',App\Models\Invoice::find($staffOp->invoice_id)->status,'Staff action closes sole pending debt');
$audit=$staffOp->fresh()->policy['unpaid_retirement'];same($staff->id,$audit['actor_id'],'Staff audit actor');same(str_repeat('🦦',500),$audit['reason'],'Staff audit reason');same('awaiting_payment',$audit['before']['operation'],'Staff audit prior state');
commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,'Replay cannot retire again'));
commerceReject(fn()=>$staffOp->fresh()->update(['policy'=>array_diff_key($staffOp->fresh()->policy,['unpaid_retirement'=>true])]));
// Released names stay released after money arrives; terminal work remains nonspendable.
App\Models\InvoiceTransaction::create(['invoice_id'=>$staffOp->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);
$retirement->inspect($staffOp->domain_id);same('rejected',$staffOp->fresh()->state,'Late money never reopens work');same(null,$staffOp->domain->fresh()->reservation_name,'Late money never steals back a reservation');
same(1,DB::table('domains_obligation_alerts')->where('domain_id',$staffOp->domain_id)->where('code','acquisition_late_money')->count(),'Late money durable review');
DB::table('invoices')->where('id',$staffOp->invoice_id)->update(['status'=>'paid']);
DB::transaction(fn()=>(new App\Services\Invoice\ProcessPaidInvoiceService)->handle(App\Models\Invoice::findOrFail($staffOp->invoice_id)));same('active',$staffOp->domain->service->fresh()->status,'Late native paid activation never throws');same(null,$staffOp->domain->service->fresh()->expires_at,'Late native paid activation keeps null expiry');$retirement->inspect($staffOp->domain_id);same('rejected',$staffOp->fresh()->state,'Late activation never reopens work');
// Match the installed after-commit wake used by the original modal scenarios.
AcquisitionRetirement::boot();
// Real staff modal success and refusal use the same locked authority.
Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();staffPanel();Auth::login($staff);
$pageClass=Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class;
$modalOp=$new('unpaid-modal.com',paid:false);Auth::login($staff);
$page=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$modalOp->domain_id])->mountAction('retire_unpaid')->assertActionMounted('retire_unpaid');
$page->setActionData(['reason'=>'Retire from real staff modal'])->callMountedAction()->assertNotified('Unpaid order retired')->assertRedirect(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$modalOp->domain_id]));
Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$modalOp->domain_id])->assertActionDoesNotExist('retire_unpaid');same(null,$modalOp->domain->fresh()->reservation_name,'Real modal invokes retirement');
$paid=$new('unpaid-refusal.com');Auth::login($staff);
$refusal=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$paid->domain_id])->mountAction('retire_unpaid');
$refusal->setActionData(['reason'=>'Paid modal must refuse'])->callMountedAction()->assertNotified('Order retirement refused');
same($paid->domain->domain,$paid->domain->fresh()->reservation_name,'Paid modal retains name');
$viewOnly=staffUser(['admin.domains.view']);Livewire\Livewire::actingAs($viewOnly)->test($pageClass,['record'=>$paid->domain_id])->assertActionDoesNotExist('retire_unpaid');
echo "Unpaid retirement staff review, audit, late money and modals passed.\n";$nativeSuccessful=true;
