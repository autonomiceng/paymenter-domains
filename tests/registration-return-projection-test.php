<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native reserved return plans and customer wording.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
require __DIR__.'/Support/owner-pages.php';
use App\Models\{Credit,Gateway,InvoiceTransaction,Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule,Refunds};
$staff=commerceUser();$staff->update(['role_id'=>Role::create(['name'=>'Return reviewer','permissions'=>['admin.domains.manage','admin.invoices.update']])->id]);
$gateway=Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic return','type'=>'gateway','enabled'=>true]);
MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='pending';$refunds=new Refunds($clock);
$failures=[];config(['settings.tickets_disabled'=>true,'domains.support_url'=>null]);
foreach(['register','transfer']as$action){
foreach(['split','returned','credited',null] as $index=>$expected){
    $op=$new('return-projection-'.$action.'-'.$index.'-placeholder.com',$expected!==null,true,$action);$op->update(['state'=>'held','safe_code'=>'staff_review']);
    $bridge=$expected==='split'?100:0;$total=PricingRule::minor($op->amount);
    if(in_array($expected,['split','returned'],true)){
        DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-receipt-'.$index,'amount'=>PricingRule::decimal($total-$bridge)]);
        if($bridge){InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);}
    }
    $beforeCredit=PricingRule::minor((string)Credit::sum('amount'));
    if($expected!==null){$refunds->refundHeld($staff,$op->id,'customer_cancelled');}
    else{$op->update(['state'=>'rejected']);$op->domain->update(['status'=>$action==='transfer'?'transfer_rejected':'registration_rejected']);}
    $saved=$op->fresh();$plan=$saved->funding['refund']??null;
    if(in_array($expected,['split','returned'],true)){
        same([$bridge,$total-$bridge,'synthetic-receipt-'.$index],[$plan['bridge_minor'],$plan['allocations'][0]['amount_minor'],$plan['allocations'][0]['receipt']],'The actual native return plan retains its exact split');
        same($beforeCredit+$bridge,PricingRule::minor((string)Credit::sum('amount')),'Only the incapable receipt becomes credit');
    }
    $money=$nativeMoney();$sends=MoneyReceiptGateway::$sends;
    for($repeat=0;$repeat<2;$repeat++){
        $dto=$owners->present($saved->domain->fresh());
        same($expected,$dto->refundTo,'Reserved return projects its actual destination: '.($expected??'no payment'));
        $expires=Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::expires($dto,null,null);
        if(($expires['value']??null)!==($action==='transfer'?'Not transferred':'Not registered')){$failures[]=$action.':'.$index.': rejected expiry lost after return';}
        $html=view('domains::partials.owner-renew',['domain'=>$dto,'summary'=>null,'support'=>null])->render();$dom=storefrontDom($html);
        if(!str_contains($html,$action==='transfer'?'Not transferred':'Not registered')){$failures[]=$action.':'.$index.': real Blade lacks rejected expiry';}
        if($dom->query('//dd/span[contains(@class,"domains-sub") and not(normalize-space())]')->length){$failures[]=$action.':'.$index.': empty expiry subline';}
        expect(!str_contains($html,'after the next sync')&&!str_contains($html,'Pay invoice'),'No future expiry or renewal payment promise');
        if(in_array($expected,['split','returned'],true)){expect(str_contains($dto->order['return']??'','checking its progress'),'Pending gateway plan retains truthful progress');}

    }
    same($money,$nativeMoney(),'Repeated projection changes no native money history');same($sends,MoneyReceiptGateway::$sends,'Rendering never calls the gateway');
    same($plan,$saved->fresh()->funding['refund']??null,'Rendering never rewrites retained allocation authority');
    if(in_array($expected,['split','returned'],true)){
        foreach($plan['allocations']as$allocation){MoneyReceiptGateway::complete(App\Models\Invoice::findOrFail($op->invoice_id),$allocation['key']);}
        Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns::resume($op->id,$clock->now());
        $done=$owners->present($saved->domain->fresh());$completed=Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::expires($done,null,null);
        if(($completed['value']??null)!==($action==='transfer'?'Not transferred':'Not registered')){$failures[]=$action.':'.$index.': completed return loses rejection';}
        expect(str_contains($done->order['return']??'','refunded')&&!str_contains($done->order['return']??'','checking its progress'),'Completed gateway result uses completed return wording');
        $money=$nativeMoney();$allocations=$op->fresh()->funding;$sends=MoneyReceiptGateway::$sends;
        Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns::resume($op->id,$clock->now());$owners->present($saved->domain->fresh());
        same([$money,$allocations,$sends],[$nativeMoney(),$op->fresh()->funding,MoneyReceiptGateway::$sends],'Completed recovery and render preserve money and allocations');
    }
}}
foreach($failures as$failure){echo 'FAIL: '.$failure."\n";}expect($failures===[],implode('; ',$failures));
echo "Native reserved return destinations passed\n";$nativeSuccessful=true;
