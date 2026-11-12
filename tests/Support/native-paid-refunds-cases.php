<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-paid-cases.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,ContactRecovery,ContactSetup,NativeActivationInformation,NativePaidHooks,Refunds,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview;
// Explicit failure before send can be credited once; original payment history is quiet.
$failed=$new(placeholderComFixtureName('paid-failed-placeholder.com'),paid:false);$preflight->supported=false;$paid($failed);$preflight->supported=true;
same('held',$failed->fresh()->state,'Unsupported registration remains held');$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.invoices.update','admin.invoices.view']);
$delayedCreate=serialize(new App\Jobs\Server\CreateJob($failed->domain->service,false));$delayedUnsuspend=serialize(new App\Jobs\Server\UnsuspendJob($failed->domain->service));$staleCreate=new App\Jobs\Server\CreateJob($failed->domain->service,false);
$refunds=new Refunds($clock);$creditBefore=number_format((float)$user->credits()->sole()->amount,2,'.','');$refunds->refundHeld($staff,$failed->id,'customer_cancelled');
same('rejected',$failed->fresh()->state,'Refund terminalizes request');same('cancelled',$failed->domain->service->fresh()->status,'Refund cancels native service');
$retirement=app(AcquisitionRetirement::class);$retirement->inspect($failed->domain_id);same(0,$alerts($failed)->count(),'Retained original refunded payment is not late money');
$credit=number_format((float)$user->credits()->sole()->amount,2,'.','');$before=$writer->calls;$manual($failed);unserialize($delayedCreate)->handle();unserialize($delayedUnsuspend)->handle();$staleCreate->handle();event(new App\Events\Invoice\Paid(Invoice::find($failed->invoice_id)));$work->operation($failed->id);$refunds->creditRejected($failed->id);
same($before,$writer->calls,'Late job after refund cannot spend');same($credit,number_format((float)$user->credits()->sole()->amount,2,'.',''),'Late wake never credits twice');
// Re-paying the same invoice reactivates native Service, never the owned request.
DB::table('invoices')->where('id',$failed->invoice_id)->update(['status'=>'pending']);$paid($failed);
same('rejected',$failed->fresh()->state,'Re-paid refunded acquisition stays terminal');same($credit,number_format((float)$user->credits()->sole()->amount,2,'.',''),'Re-payment is not auto-credited');expect($alerts($failed)->count()>=1,'New refunded-invoice money is durable review');
$versions=$alerts($failed)->count();$retirement->inspect($failed->domain_id);same($versions,$alerts($failed)->count(),'Repeated sweep is one activity');
InvoiceTransaction::create(['invoice_id'=>$failed->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);$retirement->inspect($failed->domain_id);same($versions+1,$alerts($failed)->count(),'Second late payment creates visible activity');
same('active',$failed->domain->service->fresh()->status,'Native unconditional reactivation is tolerated');
// Service reactivation alone is a discrepancy even with the original refund payment set.
$activeOnly=$new(placeholderComFixtureName('paid-active-only-placeholder.com'));$declined=InvoiceTransaction::create(['invoice_id'=>$activeOnly->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'failed']);$activeOnly->update(['state'=>'held','safe_code'=>'staff_review']);$refunds->refundHeld($staff,$activeOnly->id,'customer_cancelled');same(0,$alerts($activeOnly)->count(),'Refund itself stays quiet');
$activeOnly->domain->service->update(['status'=>'active']);same(1,$alerts($activeOnly)->count(),'Native Active without new money triggers retained discrepancy');

$nativeSuccessful=true;
