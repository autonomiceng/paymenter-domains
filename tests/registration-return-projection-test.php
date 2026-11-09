<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native reserved return plans and customer wording.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
require __DIR__.'/Support/owner-pages.php';
use App\Models\{Credit,Gateway,InvoiceTransaction,Role};
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainListRow,DomainPage,StatusPill};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,PricingRule,ReceiptReturns,ReceiptReversals,Refunds,ReminderSchedules,Reminders};
$staff=commerceUser();$staff->update(['role_id'=>Role::create(['name'=>'Return reviewer','permissions'=>['admin.domains.manage','admin.invoices.update']])->id]);
$gateway=Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic return','type'=>'gateway','enabled'=>true]);
MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='pending';$refunds=new Refunds($clock);
$failures=[];config(['settings.tickets_disabled'=>true,'domains.support_url'=>null]);
$reviewText=['Refund needs a check','Our team is checking the payment return. Contact support with the invoice number.'];
// Rendering must not reach the gateway: count status reads and allocations, and leave a callback that reports any call.
$quiet=static function(callable $render,string $label)use(&$failures){
    $trip=static function()use(&$failures,$label){$failures[]=$label.': rendering called the gateway';};
    MoneyReceiptGateway::$onStatus=$trip;MoneyReceiptGateway::$onAllocated=$trip;$calls=[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends];
    try{return $render();}
    finally{
        if($calls!==[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends]){$failures[]=$label.': rendering read or allocated a gateway return';}
        MoneyReceiptGateway::$onStatus=null;MoneyReceiptGateway::$onAllocated=null; // Deliberate recovery may call the gateway.
    }
};
// A malformed bridge amount, by kind, for a plan whose valid bridge is $bridge.
$malformed=static fn(array $plan,int $bridge)=>['missing'=>array_diff_key($plan,['bridge_minor'=>true]),'negative'=>[...$plan,'bridge_minor'=>-1],
    'string'=>[...$plan,'bridge_minor'=>(string)$bridge],'text'=>[...$plan,'bridge_minor'=>'not-an-amount'],'unbalanced'=>[...$plan,'bridge_minor'=>$bridge+1]];
$writePlan=static function(Operation $op,array $plan):void{$funding=$op->fresh()->funding;$funding['refund']=$plan;
    DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>Crypt::encryptString(json_encode($funding))]);};
// Staff summary, customer wording, destination and the list row each say the return needs review, independently.
$reviewed=static function(Operation $saved,string $label)use($owners,$user,$clock,$quiet,$reviewText,&$failures):void{
    $quiet(function()use($saved,$label,$owners,$user,$clock,$reviewText,&$failures){
        same($reviewText,ReceiptReturns::summary($saved->fresh()),$label.': staff summary needs a check');
        $dto=$owners->present($saved->domain->fresh());
        same($reviewText[1],$dto->order['return']??null,$label.': customer reads the review wording');
        same(null,$dto->refundTo,$label.': no invented return destination');
        $note=DomainPage::expires($dto,null,null)['note']??'';
        if(preg_match('/refunded|account credit|returning what you paid/',$note)){$failures[]=$label.': expiry note claims a return';}
        $now=$clock->now()->getTimestamp();$summaries=new DomainSummaries;
        expect($summaries->one($user,'managed',$saved->domain_id,$now)!==null,$label.': single summary exists');
        $row=$summaries->listed($user,$now)['managed:'.$saved->domain_id]??null;
        expect($row!==null,$label.': customer list projects the order');
        expect(StatusPill::key($row->statusState)!=='cancelled',$label.': default customer list keeps the order in view');
        try{ReceiptReversals::forInvoice(App\Models\Invoice::findOrFail($saved->invoice_id));$failures[]=$label.': staff credit context attributes a malformed plan';}
        catch(DomainException $e){same('Retained return plan needs review.',$e->getMessage(),$label.': staff credit context asks for review');}
    },$label);
};
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
    for($repeat=0;$repeat<2;$repeat++){$quiet(function()use(&$failures,&$html,&$dto,$owners,$saved,$expected,$action,$index,$user,$clock){
        $dto=$owners->present($saved->domain->fresh());
        same($expected,$dto->refundTo,'Reserved return projects its actual destination: '.($expected??'no payment'));
        $expires=Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::expires($dto,null,null);
        if(($expires['value']??null)!==($action==='transfer'?'Not transferred':'Not registered')){$failures[]=$action.':'.$index.': rejected expiry lost after return';}
        $html=view('domains::partials.owner-renew',['domain'=>$dto,'summary'=>null,'support'=>null])->render();$dom=storefrontDom($html);
        if(!str_contains($html,$action==='transfer'?'Not transferred':'Not registered')){$failures[]=$action.':'.$index.': real Blade lacks rejected expiry';}
        if($dom->query('//dd/span[contains(@class,"domains-sub") and not(normalize-space())]')->length){$failures[]=$action.':'.$index.': empty expiry subline';}
        expect(!str_contains($html,'Renewal price')&&!str_contains($html,'<dt>Renewal '),'Rejected acquisitions offer no future renewal');
        $summaries=new DomainSummaries;$now=$clock->now()->getTimestamp();
        foreach(['single'=>$summaries->one($user,'managed',$saved->domain_id,$now),'list'=>$summaries->listed($user,$now)['managed:'.$saved->domain_id]??null] as $kind=>$summary){
            expect($summary!==null,'Rejected acquisition has a '.$kind.' summary');
            $renew=DomainListRow::renewal($summary);expect($renew['value']!=='On'&&$renew['note']===null,'Rejected acquisition '.$kind.' row has no On price or renewal promise');
        }
        expect(!str_contains($html,'after the next sync')&&!str_contains($html,'Pay invoice'),'No future expiry or renewal payment promise');
        if(in_array($expected,['split','returned'],true)){expect(str_contains($dto->order['return']??'','checking its progress'),'Pending gateway plan retains truthful progress');}
    },$action.':'.$index.': render');}
    same($money,$nativeMoney(),'Repeated projection changes no native money history');same($sends,MoneyReceiptGateway::$sends,'Rendering never calls the gateway');
    same($plan,$saved->fresh()->funding['refund']??null,'Rendering never rewrites retained allocation authority');
    if($expected===null){
        if($action==='transfer'){expect(str_contains($html,'The domain stays at your current registrar.'),'Note-only rejected transfer remains visible');}
        else{config(['domains.support_url'=>'https://support.example.test/domains']);$supportOnly=view('domains::partials.owner-renew',['domain'=>$dto,'summary'=>null,'support'=>'https://support.example.test/domains'])->render();expect(str_contains($supportOnly,'Contact support'),'Support-only rejected registration remains visible');config(['domains.support_url'=>null]);}
    }
    if(in_array($expected,['split','returned'],true)){
        $raw=$saved->fresh()->getRawOriginal('funding');
        foreach($malformed($plan,$bridge) as $kind=>$bad){$writePlan($saved,$bad);
            $reviewed($saved,$action.':'.$index.': pending '.$kind);
            same($money,$nativeMoney(),'Malformed funding projection writes no money');same($sends,MoneyReceiptGateway::$sends,'Malformed funding projection never calls a gateway');}
        DB::table('domains_operations')->where('id',$saved->id)->update(['funding'=>$raw]);
    }
    if(in_array($expected,['split','returned'],true)){
        foreach($plan['allocations']as$allocation){MoneyReceiptGateway::complete(App\Models\Invoice::findOrFail($op->invoice_id),$allocation['key']);}
        Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns::resume($op->id,$clock->now());
        $done=$owners->present($saved->domain->fresh());$completed=Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::expires($done,null,null);
        if(($completed['value']??null)!==($action==='transfer'?'Not transferred':'Not registered')){$failures[]=$action.':'.$index.': completed return loses rejection';}
        expect(!str_contains($completed['note']??'','returning what you paid'),'Completed expiry note does not describe an unfinished return');
        expect(str_contains($done->order['return']??'','refunded')&&!str_contains($done->order['return']??'','checking its progress'),'Completed gateway result uses completed return wording');
        $money=$nativeMoney();$allocations=$op->fresh()->funding;$sends=MoneyReceiptGateway::$sends;
        Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns::resume($op->id,$clock->now());$owners->present($saved->domain->fresh());
        same([$money,$allocations,$sends],[$nativeMoney(),$op->fresh()->funding,MoneyReceiptGateway::$sends],'Completed recovery and render preserve money and allocations');
        // After the gateway finished, a malformed bridge still keeps the order in review and a staff repair open.
        Illuminate\Support\Facades\Mail::fake();ReminderSchedules::enroll($op->server_id);$notices=new Reminders($clock,$bindings);
        $raw=$op->fresh()->getRawOriginal('funding');$repair=fn()=>DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->exists();
        foreach($malformed($plan,$bridge) as $kind=>$bad){$label=$action.':'.$index.': completed '.$kind;
            DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>$raw]);ReceiptReturns::resume($op->id,$clock->now());
            expect(!$repair(),$label.': a valid completed plan resolves its repair');
            $writePlan($op,$bad);$before=$op->fresh()->funding;$money=$nativeMoney();$calls=[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends];
            $refunds->creditRejected($op->id);ReceiptReturns::resume($op->id,$clock->now());
            expect($repair(),$label.': automatic recovery keeps the staff repair open');
            same([$money,$before,$calls],[$nativeMoney(),$op->fresh()->funding,[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends]],$label.': recovery of a malformed plan moves no money and calls no gateway');
            $reviewed($op,$label);expect($repair(),$label.': rendering leaves the staff repair open');
            same(false,$notices->remind($op->domain_id),$label.': no account credit notice for a malformed plan');
        }
        DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>$raw]);
        same($expected==='split',$notices->remind($op->domain_id),'Only a valid split plan sends its account credit notice');
    }
}}
foreach($failures as$failure){echo 'FAIL: '.$failure."\n";}expect($failures===[],implode('; ',$failures));
echo "Native reserved return destinations passed\n";$nativeSuccessful=true;
