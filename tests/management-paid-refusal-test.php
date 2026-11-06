<?php
// Enom 444f31c refusal policy; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\{InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ManagementCommand as M,ResponseParser};
use Paymenter\Extensions\Servers\Domains\Services\{ManagementEvidence,Refunds};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management;
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-sent-refusal-placeholder.com'));$state->privacy=false;$product->server->settings()->create(['key'=>'domains_privacy_price','value'=>'5.00','type'=>'string']);$op=$submit($d,$driver,'privacy_purchase');DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);$state->fault='refused';
same('uncertain',$management->run($op->id,$driver)->state,'Sent paid refusal retains financial uncertainty');same('management_provider_refused',$op->fresh()->safe_code,'Named refusal recorded');same('sealed',$op->fresh()->attempt['seal'],'Sent refusal never becomes unsent');same(null,(new Refunds($clock))->creditRejected($op->id),'Sent refusal never credits');
$nativeBeforeReplay=nativeFinancialSnapshot($op);
$before=count($state->calls);$management->run($op->id,$driver);same($before,count($state->calls),'Sent refusal never resends');
same($nativeBeforeReplay,nativeFinancialSnapshot($op),'Refusal replay preserves native/financial state');
$nativeSuccessful=true;echo "Paid management refusal passed\n";
