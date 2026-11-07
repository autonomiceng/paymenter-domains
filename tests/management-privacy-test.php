<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic privacy native coverage.
declare(strict_types=1);
require __DIR__.'/Support/management-privacy.php';
use App\Models\{Invoice,InvoiceItem,InvoiceTransaction,Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Services\{ManagementEvidence,Refunds};
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-placeholder.com'));$state->privacy=false;$state->enabled=false;$state->cost='unknown';
$forms=new \Paymenter\Extensions\Servers\Domains\Services\ManagementForms($management,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts(fn()=>$driver));
$before=[Invoice::count(),$d->operations()->count(),$state->calls];
commerceReject(fn()=>$forms->submit($user,$d,'privacy_purchase',['privacy'=>'1'],$key()));
same($before,[Invoice::count(),$d->operations()->count(),$state->calls],'Hidden privacy form creates no purchase, invoice or provider read');
$op=$submit($d,$driver,'privacy_purchase');same('awaiting_payment',$op->state,'Native privacy invoice awaits payment');expect((float)$op->amount>0,'Positive taxed retail');same('paid_invoice',$op->funding_basis,'No nonbillable privacy purchase');$n=Invoice::count();$reads=count($state->calls);same($op->id,$submit($d,$driver,'privacy_purchase')->id,'Distinct purchase nonce reuses outstanding debt');same($n,Invoice::count(),'No duplicate purchase invoice');same($reads,count($state->calls),'Reuse before provider reads');
$invoice=Invoice::findOrFail($op->invoice_id);$item=InvoiceItem::findOrFail($op->invoice_item_id);same('WHOIS privacy: manage-privacy-placeholder.com (1 year, Oct 18, 2026 to Oct 18, 2027)', $item->description, 'Privacy purchase uses expected independent annual term');same(null,$item->reference_type,'Privacy line no native service routing');same(1,$invoice->items()->count(),'Singleton issued line');
$nonce=substr($op->operation_key,-64);$reads=count($state->calls);same($op->id,$management->request($user,$d->id,'privacy_purchase',[],$nonce,$driver)->id,'Privacy invoice idempotent');same($reads,count($state->calls),'Idempotent billing no extra reads');
same('awaiting_payment',$management->run($op->id,$driver)->state,'Unpaid invoice remains payable');
commerceReject(fn()=>$invoice->items()->create(['price'=>'1.00','quantity'=>1,'description'=>'foreign']));commerceReject(fn()=>$item->update(['price'=>'0.00']));commerceReject(fn()=>$invoice->update(['user_id'=>commerceUser()->id]));
$pay($op);$state->cost='8.00';$state->balance='100';
same('succeeded',$management->run($op->id,$driver)->state,'Paid privacy confirms full year and captured order');same('7001',$op->fresh()->attempt['management_ack']['order_id'],'Write order retained separately from domain acquisition');same(true,ManagementEvidence::resolved($op->fresh(),$d),'Privacy completion proof accepted');same(null,$d->service->fresh()->expires_at,'Privacy never annualizes native service');
$balanceRow=DB::table('domains_registrar_balances')->where('server_id',$op->server_id)->where('account_fingerprint',$op->account_fingerprint)->sole();
same(['100.00','USD',null,'preflight'],[$balanceRow->amount,$balanceRow->currency,$balanceRow->last_error_code,$balanceRow->source],'Successful privacy preflight retains exact balance without failure');
same(1,count(array_filter($state->calls,fn($call)=>$call[0]==='GetBalance')),'Privacy preparation makes exactly one balance exchange');
DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>'2031-01-01']);same($item->description,$item->fresh()->description,'Domain expiry change never rewrites independent privacy cover');DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>'2027-10-18']);
Cache::flush();$state->expiry='2027-10-18';$renew=$submit($d,$driver,'privacy_renew');$n=Invoice::count();same($renew->id,$submit($d,$driver,'privacy_renew')->id,'Distinct renewal nonce reuses outstanding debt');same($n,Invoice::count(),'No duplicate renewal invoice');same('WHOIS privacy: manage-privacy-placeholder.com (1 year, Oct 18, 2027 to Oct 18, 2028)', InvoiceItem::findOrFail($renew->invoice_item_id)->description, 'Privacy renewal starts at frozen privacy expiry, independently of domain expiry');$pay($renew);$state->balance='1,000';same('succeeded',$management->run($renew->id,$driver)->state,'Paid renewal extends frozen privacy base exactly one year');
Cache::flush();$forms->submit($user,$d,'privacy_disable',[],$key());$toggle=$d->operations()->where('action','privacy_disable')->sole();same('succeeded',$management->run($toggle->id,$driver)->state,'Owned subscription disabled without invoice');same(null,$toggle->invoice_id,'Toggle nonbillable');Cache::flush();$forms->submit($user,$d,'privacy_enable',[],$key());$toggle=$d->operations()->where('action','privacy_enable')->sole();same('succeeded',$management->run($toggle->id,$driver)->state,'Owned subscription re-enabled');

$nativeSuccessful=true;echo "Native privacy purchases, renewals and toggles passed\n";
