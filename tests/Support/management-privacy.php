<?php
// Enom 444f31c privacy native invoice/readback/refund parity; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/management.php';
require __DIR__.'/staff-panel.php';
\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
use App\Models\{Invoice,InvoiceItem,InvoiceTransaction,Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Services\{ManagementEvidence,Refunds};
$server=$product->server;$server->settings()->create(['key'=>'domains_privacy_price','value'=>'5.00','type'=>'string']);
$pay=static function($op){DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);};
$role=Role::create(['name'=>'Privacy refund','permissions'=>['admin.domains.manage','admin.invoices.update']]);$staff=commerceUser();$staff->update(['role_id'=>$role->id]);$refunds=new Refunds($clock);
$state->cost='8.00';$state->balance='100.00';
