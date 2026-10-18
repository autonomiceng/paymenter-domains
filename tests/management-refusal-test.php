<?php
// Enom 444f31c refusal policy; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\{InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ManagementCommand as M,ResponseParser};
use Paymenter\Extensions\Servers\Domains\Services\Refunds;
$parse=static fn($body)=>(new ResponseParser)->parse(M::Purchase,$body);
$body='<interface-response><Command>PurchaseServices</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>524181</RRPCode><errors><Err1>Insufficient balance</Err1></errors></interface-response>';
same('rejected',$parse($body)->status,'Documented financial refusal');same('unknown',$parse(str_replace('</interface-response>','<OrderID>123</OrderID></interface-response>',$body))->status,'Order alongside refusal remains unknown');same('unknown',$parse(str_replace('524181','2400',$body))->status,'System code remains unknown');
[$d,$driver]=$holding('manage-privacy-sent-refusal.com');$state->privacy=false;$product->server->settings()->create(['key'=>'domains_privacy_price','value'=>'5.00','type'=>'string']);$op=$submit($d,$driver,'privacy_purchase');DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);$state->fault='refused';
same('uncertain',$management->run($op->id,$driver)->state,'Sent paid refusal retains financial uncertainty');same('management_provider_refused',$op->fresh()->safe_code,'Named refusal recorded');same('sealed',$op->fresh()->attempt['seal'],'Sent refusal never becomes unsent');same(null,(new Refunds($clock))->creditRejected($op->id),'Sent refusal never credits');
$before=count($state->calls);$management->run($op->id,$driver);same($before,count($state->calls),'Sent refusal never resends');
$nativeSuccessful=true;echo "management refusal passed\n";
