<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
$payCheckCase='walk3';
require __DIR__.'/Support/pay-check.php';
$nativeSuccessful=false;
$op=$new('walk3-issued-placeholder.com',false);$invoice=App\Models\Invoice::findOrFail($op->invoice_id);
$fresh();$funds();
Illuminate\Support\Facades\DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['processing_enabled'=>false]);
same(null,$stop($invoice),'An issued invoice remains payable while Orders is off');
$pay($invoice)->assertHasNoErrors();
same('paid',$invoice->fresh()->status,'The issued invoice accepts payment');
$claims->claim($op->id);
same(['held','processing'],[$op->fresh()->state,$op->fresh()->safe_code],'Paid work stays held while Orders is off');
commerceReject(fn()=>$offers->offer('com','register',1));
$nativeSuccessful=true;echo "Issued invoice payment with Orders off passed.\n";
