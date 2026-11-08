<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native reserved return plans and customer wording.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
require __DIR__.'/Support/owner-pages.php';
use App\Models\{Credit,Gateway,InvoiceTransaction,Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainPage;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule,Refunds};
$staff=commerceUser();$staff->update(['role_id'=>Role::create(['name'=>'Return reviewer','permissions'=>['admin.domains.manage','admin.invoices.update']])->id]);
$gateway=Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic return','type'=>'gateway','enabled'=>true]);
MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='pending';$refunds=new Refunds($clock);
foreach(['split','returned','credited',null] as $index=>$expected){
    $op=$new('return-projection-'.$index.'-placeholder.com',$expected!==null);$op->update(['state'=>'held','safe_code'=>'staff_review']);
    $bridge=$expected==='split'?100:0;$total=PricingRule::minor($op->amount);
    if(in_array($expected,['split','returned'],true)){
        DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-receipt-'.$index,'amount'=>PricingRule::decimal($total-$bridge)]);
        if($bridge){InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);}
    }
    $beforeCredit=PricingRule::minor((string)Credit::sum('amount'));
    if($expected!==null){$refunds->refundHeld($staff,$op->id,'customer_cancelled');}
    else{$op->update(['state'=>'rejected']);$op->domain->update(['status'=>'registration_rejected']);}
    $saved=$op->fresh();$plan=$saved->funding['refund']??null;
    if(in_array($expected,['split','returned'],true)){
        same([$bridge,$total-$bridge,'synthetic-receipt-'.$index],[$plan['bridge_minor'],$plan['allocations'][0]['amount_minor'],$plan['allocations'][0]['receipt']],'The actual native return plan retains its exact split');
        same($beforeCredit+$bridge,PricingRule::minor((string)Credit::sum('amount')),'Only the incapable receipt becomes credit');
    }
    $money=$nativeMoney();$sends=MoneyReceiptGateway::$sends;
    for($repeat=0;$repeat<2;$repeat++){
        $dto=$owners->present($saved->domain->fresh());
        same($expected,$dto->refundTo,'Reserved return projects its actual destination: '.($expected??'no payment'));
        $note=DomainPage::expires($dto,null,null)['note'];
        expect($expected!=='split'||str_contains($note,'account credit otherwise'),'Customer sees both parts of the split return');
    }
    same($money,$nativeMoney(),'Repeated projection changes no native money history');same($sends,MoneyReceiptGateway::$sends,'Rendering never calls the gateway');
    same($plan,$saved->fresh()->funding['refund']??null,'Rendering never rewrites retained allocation authority');
}
echo "Native reserved return destinations passed\n";$nativeSuccessful=true;
