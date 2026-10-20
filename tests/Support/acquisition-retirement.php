<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/operation-fixture.php';
require __DIR__.'/staff-panel.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);
NativeIntentSchema::install();ReminderSchema::install();NativeIdentity::boot();NativeCancellation::boot();
$retirement=new AcquisitionRetirement($clock);app()->instance(AcquisitionRetirement::class,$retirement);app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$permissions=['admin.domains.view','admin.domains.manage',AcquisitionRetirement::PERMISSION];$staff=staffUser($permissions);
$ticket=static fn($op,$actor=null)=>StaffHoldingReview::ticket((int)($actor??$staff)->id,(int)$op->domain_id,'retire_unpaid',StaffHoldingReview::stamp(HoldingLocks::take($op->domain->fresh(),advisory:true)),$clock);
$cancel=static function($op):void {
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'cancelled']);
    DB::table('services')->where('id',$op->service_id)->update(['status'=>'cancelled']);
};
