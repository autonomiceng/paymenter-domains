<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/staff-panel.php';
use App\Models\{Credit,Gateway,Service,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureProof,HoldingDeparture,HoldingLocks,MoneyOutcome,PricingRule,Refunds,StaffHoldingReview};

$nativeSuccessful=false;
staffPanel();
require __DIR__.'/Support/money-receipt.php';
Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic card receipt','type'=>'gateway','enabled'=>true]);
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$cash=static fn(int $owner)=>PricingRule::minor((string)(Credit::where('user_id',$owner)->where('currency_code','USD')->value('amount')??'0.00'));
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,'cancelled'],[$result->outcome,$invoice->fresh()->status],'The departure closes the unpaid renewal invoice');
$departed=$d->fresh()->getAttributes();

// Another customer now holds the same name on a new row.
$stranger=User::create(['first_name'=>'New','last_name'=>'Holder','email'=>'new-holder@example.test','password'=>'synthetic-password']);
Credit::create(['user_id'=>$stranger->id,'currency_code'=>'USD','amount'=>'2.00']);
$old=$d->service;
$service=Service::withoutEvents(fn()=>Service::create(['user_id'=>$stranger->id,'order_id'=>$old->order_id,'product_id'=>$old->product_id,'plan_id'=>$old->plan_id,'status'=>'active','quantity'=>1,'price'=>$old->price,'currency_code'=>'USD']));
$row=DB::table('domains_domains')->where('id',$d->id)->first();$row=(array)$row;unset($row['id']);
$newId=(int)DB::table('domains_domains')->insertGetId([...$row,'user_id'=>$stranger->id,'service_id'=>$service->id,'reservation_name'=>$d->domain,'status'=>'active','renewal_policy'=>null]);
$newRow=(array)DB::table('domains_domains')->where('id',$newId)->first();
$owner=(int)$d->user_id;$before=$cash($owner);$amount=PricingRule::minor((string)$renewal->amount);

// A stale pay form pays the old cancelled renewal invoice after the departure.
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),'MoneyReceipt',$renewal->amount,'0.00','departed-late-receipt');
same(1,App\Models\InvoiceTransaction::where('invoice_id',$invoice->id)->where('transaction_id','departed-late-receipt')->count(),'The late receipt is kept on the old invoice');
$refunds=new Refunds($clock);$refunds->creditRejected((int)$renewal->id);$refunds->creditRejected((int)$renewal->id);
same($before+$amount,$cash($owner),'The late receipt returns once as the old owner\'s account credit');
same(200,$cash((int)$stranger->id),"The new holder's credit is unchanged");
same($newRow,(array)DB::table('domains_domains')->where('id',$newId)->first(),'The new holding is untouched');
same(0,DB::table('domains_operations')->where('domain_id',$newId)->count(),'The new holding gets no renewal or refund work');
$held=$d->fresh();
same(['departed',null,true,'cancelled'],[$held->status,$held->reservation_name,DepartureProof::verified($held),$held->service->status],'The departed row restores no name, service or renewal');
same(['rejected','renewal_cancelled_unpaid'],[$renewal->fresh()->state,$renewal->fresh()->safe_code],'The closed renewal stays closed');
same($departed['renewal_policy'],$held->getAttributes()['renewal_policy'],'The frozen record is unchanged');
same([],Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns::plans($renewal->fresh()),'A locally available late card receipt needs no gateway return plan');
same([0,0],[MoneyReceiptGateway::$sends,MoneyReceiptGateway::$statusCalls],'A departure credits local money without gateway return calls');
echo "A late card receipt on a departed holding credits the old owner and never touches the new holding.\n";
$nativeSuccessful=true;
