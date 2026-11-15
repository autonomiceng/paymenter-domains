<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
$payCheckCase='walk3';
require __DIR__.'/Support/pay-check.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Intent,Query};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,PricingRule,RetailLine};
$nativeSuccessful=false;
$op=$new('walk3-issued-placeholder.com',false);$invoice=App\Models\Invoice::findOrFail($op->invoice_id);
$fresh();$funds();
Illuminate\Support\Facades\DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['processing_enabled'=>false]);
$binding=AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole());
$query=new Query($binding,['com'],'register');
// Use the staff pricing command, which republishes retail but retains native prices while Orders is off.
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:3,transfers:true),[...PricingRule::DEFAULT,'markup'=>'50.00']),
    $ports->readOffers($query,Context::Staff)[0]->revision);
$nativePrice=$product->plans()->where('billing_period',1)->sole()->prices()->where('currency_code','USD')->sole();
same($op->amount,RetailLine::decimal($nativePrice->price),'Orders off retains the original native price');
same('9.00000000',(string)Paymenter\Extensions\Servers\Domains\Models\Price::where(['server_id'=>$op->server_id,'tld'=>'com','action'=>'register','term'=>1])->sole()->retail,'The staff edit changes current retail');
$before=PricingRule::minor($cash());
same(null,$stop($invoice),'An issued invoice remains payable while Orders is off');
$pay($invoice)->assertHasNoErrors();
same('paid',$invoice->fresh()->status,'The issued invoice accepts payment');
same(PricingRule::minor($op->amount),$before-PricingRule::minor($cash()),'Native payment collects the original invoice amount');
same($op->amount,RetailLine::decimal($invoice->fresh()->transactions()->sole()->amount),'The retained payment uses the issued amount');
$assertNoSend($op);
same(0,$writer->calls,'Orders off sends no registrar request');
same(['held','processing'],[$op->fresh()->state,$op->fresh()->safe_code],'Paid work stays held while Orders is off');
commerceReject(fn()=>$offers->offer('com','register',1));
$nativeSuccessful=true;echo "Issued invoice payment with Orders off passed.\n";
