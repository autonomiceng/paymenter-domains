<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureReadyRegistration = true;
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
require __DIR__.'/Support/reminder-mail.php';
use App\Models\{Credit,Gateway,Invoice,InvoiceTransaction,Role,Service};
use Illuminate\Support\Facades\{Auth,DB,Mail};
use Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions;
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,InvoiceMoney,MoneyDecisions,NativePresenter,OwnedDomains,PricingRule,ReceiptReturns,ReceiptReversals,RefundPayment,Refunds,ReminderSchedules,Reminders,StaffOperationReview,StaffReviewRefused};
use Paymenter\Extensions\Servers\Domains\Holding\Retention\HistoryRetention;

class ReleaseGateway extends MoneyReceiptGateway
{
    public static array $observations=[]; public static array $receipts=[]; public static bool $unreadable=false;
    public static array $attempts=[]; public static bool $beforeSend=false;
    public function receiptLedger(Invoice $invoice): array
    {
        if(self::$unreadable){throw new RuntimeException('Synthetic ledger failure');}
        $rows=self::$observations[$invoice->id]??[];
        foreach(self::$returns as $key=>$r){
            $receipt=self::$receipts[$key]??null;
            if(!$receipt||$receipt['invoice_id']!==$invoice->id){continue;}
            $rows[]=[...$receipt,...$r,'allocation_key'=>$key,'return_key'=>$r['key']];
        }
        return $rows;
    }
    public function allocateReceiptReturn(Invoice $invoice,string $receipt,string $key,int $minor): array
    {
        self::$attempts[]=[$key,$minor];
        self::$receipts[$key]=['invoice_id'=>$invoice->id,'user_id'=>$invoice->user_id,'currency'=>'USD','payment_intent_id'=>$receipt];
        if(self::$beforeSend){self::$beforeSend=false;throw new RuntimeException('Synthetic crash before gateway acceptance');}
        return parent::allocateReceiptReturn($invoice,$receipt,$key,$minor);
    }
    public function receiptReversals(Invoice $invoice,string $receipt): array
    {
        return array_map(fn($r)=>[...$r,'kind'=>'dispute','id'=>'synthetic_dispute','status'=>$r['state'],
            'credited_minor'=>0,'needs_review'=>false],self::$observations[$invoice->id]??[]);
    }
}
class_alias(ReleaseGateway::class,'Paymenter\\Extensions\\Gateways\\Release\\Release');
$gateway=Gateway::create(['extension'=>'Release','name'=>'Offline release','type'=>'gateway','enabled'=>true]);
$staff=commerceUser();$staff->update(['role_id'=>Role::create(['name'=>'Release refunds','permissions'=>['admin.domains.manage','admin.invoices.update']])->id]);
// Request actions read as a staff viewer; the money calls below run without one.
$viewer=commerceUser();$viewer->update(['role_id'=>Role::create(['name'=>'Release viewer','permissions'=>['admin.domains.view']])->id]);
$offers=static function(Operation $op)use($viewer):bool{
    $prior=Auth::user();Auth::setUser($viewer);
    try{return OperationActions::relevant('credit',$op);}finally{$prior?Auth::setUser($prior):Auth::forgetUser();}
};
$refunds=new Refunds($clock);MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='refunded';
$balance=static fn()=>PricingRule::minor((string)(Credit::where('user_id',$user->id)->value('amount')??'0.00'));
$failures=[];
$check=static function(bool $ok,string $message)use(&$failures):void{if(!$ok){$failures[]=$message;echo 'RELEASE_FAIL '.$message."\n";}};
$equal=static fn($expected,$actual,string $message)=>$check($expected===$actual,$message);
$case=static function(string $name,Closure $run)use(&$failures):void{try{$run();}catch(Throwable $e){$failures[]=$name.': '.$e::class.' '.$e->getMessage();echo 'RELEASE_FAIL '.end($failures)."\n";}};
$make=static function(string $suffix,string $action='register')use($new,$gateway):Operation{
    $op=$new('release-'.$suffix.'-placeholder.com',true,true,$action);$op->update(['state'=>'held','safe_code'=>'staff_review']);
    DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic_receipt_'.$op->id]);
    return $op;
};
$observe=static function(Operation $op,string $state,int $minor):void{
    ReleaseGateway::$observations[$op->invoice_id]=[['invoice_id'=>$op->invoice_id,'user_id'=>$op->domain->user_id,'currency'=>'USD',
        'payment_intent_id'=>'synthetic_receipt_'.$op->id,'allocation_key'=>'stripe:dispute:synthetic_'.$op->id,'state'=>$state,'amount_minor'=>$minor]];
};
$reserve=static function(Operation $op)use($refunds,$staff):array{
    MoneyReceiptGateway::$onStatus=static fn()=>throw new RuntimeException('Synthetic crash after plan');
    try{$refunds->refundHeld($staff,$op->id,'customer_cancelled');}catch(RuntimeException $e){if($e->getMessage()!=='Synthetic crash after plan'){throw $e;}}
    return $op->fresh()->funding['refund']['allocations'][0];
};
$release=static function(Operation $op,array $a):void{DB::transaction(fn()=>ReceiptReturns::release($op,$a['key'],'receipt_changed'));};
$notice=static function(Operation $op)use($clock):?array{
    return DB::transaction(static function()use($op,$clock):?array{
        $l=HoldingLocks::take($op->domain);$alerts=[];
        return (new ReflectionMethod(Reminders::class,'refund'))->invokeArgs(null,[$l,$clock->now(),&$alerts]);
    });
};

foreach(['full','split','bridge','invalid','zero'] as $mode){$case('released credit readers '.$mode,function()use($mode,$make,$reserve,$release,$refunds,$notice,$equal,$offers):void{
    $op=$make('readers-'.$mode);$minor=PricingRule::minor($op->amount);
    if(in_array($mode,['split','bridge'],true)){
        DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['amount'=>PricingRule::decimal($minor-100)]);
        InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded',
            'gateway_id'=>$mode==='split'?InvoiceTransaction::where('invoice_id',$op->invoice_id)->first()->gateway_id:null,
            'transaction_id'=>'synthetic_reader_second_'.$op->id]);
    }
    $a=$reserve($op);$release($op,$a);
    if($mode==='split'){
        $pending=$op->fresh();
        $equal(true,$offers($pending),'A readable unfinished return offers Retry refund');
        $equal('split',OwnedDomains::refundTo($pending->domain,$pending,[]),'An unfinished remaining allocation keeps its planned card return beside released credit');
        $refunds->creditRejected($op->id);
    }
    $saved=$op->fresh();$d=$saved->domain;
    if(in_array($mode,['invalid','zero'],true)){
        // Historical damaged evidence must be readable without editing the immutable saved result.
        $funding=$saved->funding;$funding['receipt_return_results'][$a['key']]['credit_minor']=$mode==='invalid'?$minor+1:0;
        if($mode==='zero'){$funding['receipt_return_results'][$a['key']]['credit_id']=null;}
        DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($funding,JSON_THROW_ON_ERROR))]);
        $saved=$op->fresh();
    }
    $expected=in_array($mode,['invalid','zero'],true)?null:($mode==='split'?'split':'credited');
    $equal($expected,OwnedDomains::refundTo($d,$saved,[]),'Refund destination follows released credit: '.$mode);
    $planned=$notice($saved);
    $equal(in_array($mode,['invalid','zero'],true)?null:'$'.PricingRule::decimal(match($mode){'split'=>$minor-100,'bridge'=>100,default=>$minor}),$planned['data']['amount']??null,'Credit notice reports its separate committed credit: '.$mode);
    if($mode==='invalid'){
        $order=OwnedDomains::order($d,$saved,Invoice::findOrFail($op->invoice_id));
        $equal(false,$order['returned']??null,'Invalid released credit keeps the customer order under review');
        // The other readers say review too, without an error.
        $presenter=(new ReflectionClass(NativePresenter::class))->newInstanceWithoutConstructor();
        $equal(['pending',null],(new ReflectionMethod(NativePresenter::class,'outcomeMoney'))->invoke($presenter,[$saved],Invoice::findOrFail($op->invoice_id)),'Invalid released credit keeps the invoice outcome pending');
        $equal(ReceiptReturns::REVIEW,ReceiptReturns::summary($saved),'Invalid released credit summary is under review');
        // Staff request actions and History read it through settled.
        $equal(false,ReceiptReturns::settled($saved),'Invalid released credit is not settled');
        // No retry can replace released evidence, so Retry refund would only fail.
        $equal(false,$offers($saved),'Invalid released credit offers no Retry refund');
    }
});}

$makeTen=static function(string $suffix)use($make,$tlds,$prices):Operation{
    $tld=$tlds[0]->fresh();$saved=$tld->catalogue_intent;
    $intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($saved);
    try{
        $rule=PricingRule::validate(['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>'10.00','renew'=>'10.00','transfer'=>'10.00']]);
        $tld->update(['catalogue_intent'=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json([...$intent,'pricing_rule'=>$rule])]);
        activeFixtureRepublish($prices,$tld->fresh());return $make($suffix);
    }finally{$tld->update(['catalogue_intent'=>$saved]);activeFixtureRepublish($prices,$tld->fresh());}
};
foreach(['released','whole'] as $mode){foreach(['credited','refunded'] as $outcome){$case('refund destination '.$mode.' '.$outcome,function()use($mode,$outcome,$makeTen,$reserve,$release,$refunds,$equal):void{
    $op=$makeTen('destination-'.$mode.'-'.$outcome);
    if($mode==='released'){
        $row=InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole();$row->update(['amount'=>'2.00']);
        InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'8.00','fee'=>'0.00','status'=>'succeeded',
            'gateway_id'=>$row->gateway_id,'transaction_id'=>'synthetic_destination_'.$op->id]);
    }
    $first=$reserve($op);if($mode==='released'){$release($op,$first);}
    $remaining=ReceiptReturns::effective($op->fresh())[0]['allocations'];$invoice=Invoice::findOrFail($op->invoice_id);
    foreach($remaining as $a){
        (new ReleaseGateway)->allocateReceiptReturn($invoice,$a['receipt'],$a['key'],$a['amount_minor']);
        if($outcome==='credited'){MoneyReceiptGateway::credit($invoice,$a['key']);}
    }
    $refunds->creditRejected($op->id);$saved=$op->fresh();
    $equal(0,ReceiptReturns::outstanding($saved),'Destination is checked after every remaining allocation completes');
    $expected=$outcome==='credited'?'credited':($mode==='released'?'split':'returned');
    $equal($expected,OwnedDomains::refundTo($saved->domain,$saved,[]),'Refund destination follows completed allocations and released credit: '.$mode.' '.$outcome);
});}}
$mails=static fn(Operation $op)=>Mail::queued(DomainNotice::class)->filter(fn($mail)=>$mail->data['domain']===$op->domain->domain)->map(fn($mail)=>$mail->data['amount'])->values()->all();
foreach(['delivered','retained'] as $mode){$case('release after bridge '.$mode,function()use($mode,$makeTen,$reserve,$observe,$refunds,$equal,$balance,$clock,$bindings,$mails):void{
    $op=$makeTen('notice-'.$mode);$before=$balance();
    DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['amount'=>'9.00']);
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded','transaction_id'=>'synthetic_bridge_'.$op->id]);
    $a=$reserve($op);ReminderSchedules::enroll($op->server_id);$reminders=new Reminders($clock,$bindings);
    $equal($before+100,$balance(),'Only the bridge is credited before release');
    $equal(true,$reminders->remind($op->domain_id),'Bridge notice is delivered before release');
    $equal(['$1.00'],$mails($op),'Bridge notice contains only its committed dollar');
    if($mode==='retained'){
        HistoryRetention::sweep('reminders',$clock->now()->modify('+14 months')->getTimestamp());
        $equal(0,DB::table('domains_reminder_deliveries')->where('domain_id',$op->domain_id)->count(),'Bridge delivery leaves active history');
        $equal(true,HistoryRetention::replayed('reminder',hash('sha256',$op->domain_id.':refund:'.$op->id)),'Retained bridge identity remains remembered');
    }
    $observe($op,'won',900);$refunds->creditRejected($op->id);
    $equal(900,$op->fresh()->funding['receipt_return_results'][$a['key']]['credit_minor']??null,'Retry separately commits nine dollars of released credit');
    $equal($before+1000,$balance(),'Both committed credits reach the account');
    $equal(true,$reminders->remind($op->domain_id),'Later released credit receives a new notice');
    $refunds->creditRejected($op->id);$refunds->creditRejected($op->id);
    $equal(false,(new Reminders($clock,$bindings))->remind($op->domain_id),'Released credit notice stays deduplicated after retry and restart');
    $equal(['$1.00','$9.00'],$mails($op),'Exactly one nine-dollar notice follows the bridge without repeating it');
});}
$case('sequential release notices',function()use($make,$reserve,$release,$refunds,$equal,$balance,$clock,$bindings,$mails):void{
    $op=$make('sequential-notices');$minor=PricingRule::minor($op->amount);$before=$balance();
    $row=InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole();$row->update(['amount'=>'2.00']);
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>PricingRule::decimal($minor-200),'fee'=>'0.00','status'=>'succeeded',
        'gateway_id'=>$row->gateway_id,'transaction_id'=>'synthetic_sequential_'.$op->id]);
    $reserve($op);$allocations=$op->fresh()->funding['refund']['allocations'];
    ReminderSchedules::enroll($op->server_id);$reminders=new Reminders($clock,$bindings);
    $equal(false,$reminders->remind($op->domain_id),'An uncredited plan sends no credit notice');
    foreach($allocations as $i=>$a){
        $release($op,$a);
        $equal(true,$reminders->remind($op->domain_id),'Each separately committed release is notified: '.$i);
        $equal(false,$reminders->remind($op->domain_id),'Each release notice is sent once: '.$i);
    }
    $refunds->creditRejected($op->id);$refunds->creditRejected($op->id);
    $equal(false,(new Reminders($clock,$bindings))->remind($op->domain_id),'Retries never repeat either sequential release');
    $equal(['$2.00','$'.PricingRule::decimal($minor-200)],$mails($op),'Sequential notices contain each credited amount rather than the cumulative total');
    $equal($before+$minor,$balance(),'Sequential release retries preserve the exact credited total');
});

$case('same key resend',function()use($make,$refunds,$staff,$equal,$balance):void{
    $op=$make('resend');$before=$balance();ReleaseGateway::$beforeSend=true;
    try{$refunds->refundHeld($staff,$op->id,'customer_cancelled');}catch(RuntimeException){}
    $a=$op->fresh()->funding['refund']['allocations'][0];
    $equal('sending',$op->fresh()->funding['receipt_return_results'][$a['key']]['state']??null,'Send intent survives crash before the first gateway acceptance');
    $refunds->creditRejected($op->id);$refunds->creditRejected($op->id);
    $equal([[$a['key'],$a['amount_minor']],[$a['key'],$a['amount_minor']]],array_slice(ReleaseGateway::$attempts,-2),'Resend uses the same key and amount');
    $equal('refunded',$op->fresh()->funding['receipt_return_results'][$a['key']]['state'],'Resent allocation completes');
    $equal($before,$balance(),'Resend creates no local credit');
});
$case('retained sending key with no gateway row',function()use($make,$reserve,$refunds,$equal):void{
    $op=$make('retained-sending');$a=$reserve($op);$saved=$op->fresh();
    $saved->update(['funding'=>[...$saved->funding,'receipt_return_results'=>[$a['key']=>['state'=>'sending','amount_minor'=>$a['amount_minor'],'at'=>'2026-11-15T00:00:00+00:00']]]]);
    $before=count(ReleaseGateway::$attempts);$refunds->creditRejected($op->id);
    $equal([[$a['key'],$a['amount_minor']]],array_slice(ReleaseGateway::$attempts,$before),'Successful null status resends a retained sending key');
    $equal('refunded',$op->fresh()->funding['receipt_return_results'][$a['key']]['state'],'Retained sending key completes after retry');
});
// Each transaction takes the holding prefix once: release reuses its caller's locks instead of retaking them after the ledger read.
$prefix=['on'=>false,'takes'=>0,'begins'=>0];
DB::listen(static function($q)use(&$prefix):void{if($prefix['on']&&preg_match('/from "users" where "users"\."id" in \(.*\) order by "id" asc/',$q->sql)){$prefix['takes']++;}});
\Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class,static function()use(&$prefix):void{if($prefix['on']){$prefix['begins']++;}});
$case('release coverage',function()use($make,$reserve,$observe,$refunds,$equal,$balance,$check,&$prefix):void{
    $op=$make('coverage');$a=$reserve($op);$before=$balance();$sends=MoneyReceiptGateway::$sends;
    $observe($op,'lost',100);$prefix['on']=true;$refunds->creditRejected($op->id);$prefix['on']=false;$saved=$op->fresh();
    $equal($prefix['begins'],$prefix['takes'],'Each money transaction takes the holding locks once, including its release');$r=$saved->funding['receipt_return_results'][$a['key']]??[];
    $equal('released',$r['state']??null,'Reduced receipt releases its unsent allocation');
    $equal($a['amount_minor']-100,$r['credit_minor']??null,'Release pays only the still held share');
    $equal($before+$a['amount_minor']-100,$balance(),'Released credit is saved exactly');
    $equal($sends,MoneyReceiptGateway::$sends,'Released allocation never reaches the card');
    $equal(0,ReceiptReturns::outstanding($saved),'Released allocation is no longer outstanding');
    $equal([],ReceiptReturns::reversals($saved,InvoiceTransaction::where('invoice_id',$op->invoice_id)->get()),'Released credit has no native reversal');
    $equal('Credit added',ReceiptReturns::summary($saved)[0]??null,'Summary identifies actual released account credit');
    $equal([$a['transaction_id']=>$a['amount_minor']-100],RefundPayment::lateCredited($saved),'Released payment coverage excludes the lost amount');
    $equal($a['amount_minor']-100,ReceiptReversals::forInvoice(Invoice::findOrFail($op->invoice_id))[0]['domains_credited_minor'],'Staff reversal context attributes released credit to its receipt');
    $equal(null,OperationActions::refundPending($saved,'guard_refused'),'Released result does not ask staff to retry a finished allocation');
    $presenter=(new ReflectionClass(NativePresenter::class))->newInstanceWithoutConstructor();
    $method=new ReflectionMethod(NativePresenter::class,'outcomeMoney');
    $equal(['pending','$'.PricingRule::decimal($a['amount_minor']-100)],$method->invoke($presenter,[$saved],Invoice::findOrFail($op->invoice_id)),'Native outcome uses actual released coverage and total');
    $refused=false;try{$saved->update(['funding'=>[...$saved->funding,'receipt_return_results'=>[$a['key']=>[...$r,'credit_minor'=>0]]]]);}catch(DomainException){$refused=true;}
    $check($refused,'Released result refuses later credit edits');
    $before=$balance();$refunds->creditRejected($op->id);$refunds->creditRejected($op->id);$equal($before,$balance(),'Release replay never adds credit again');
    DB::transaction(function()use($op,$equal):void{$l=HoldingLocks::take($op->domain);$equal(0,InvoiceMoney::held(InvoiceMoney::load($l,$op->invoice_id)),'Projection counts released credit once');});
});
$case('unreadable ledger',function()use($make,$reserve,$release,$balance,$equal,$check):void{
    $op=$make('unreadable');$a=$reserve($op);$before=$balance();ReleaseGateway::$unreadable=true;$refused=false;
    try{$release($op,$a);}catch(RuntimeException){$refused=true;}finally{ReleaseGateway::$unreadable=false;}
    $check($refused,'Unreadable ledger refuses release');$equal($before,$balance(),'Failed ledger check writes no credit');
    $equal(null,$op->fresh()->funding['receipt_return_results'][$a['key']]??null,'Failed ledger check writes no result');
});
$case('legacy gateway allocation',function()use($make,$reserve,$release,$equal,$balance):void{
    $op=$make('legacy');$a=$reserve($op);$invoice=Invoice::findOrFail($op->invoice_id);
    (new ReleaseGateway)->allocateReceiptReturn($invoice,$a['receipt'],$a['key'],$a['amount_minor']);$before=$balance();
    $release($op,$a);$saved=$op->fresh();
    $equal('refunded',$saved->funding['receipt_return_results'][$a['key']]['state'],'Ledger allocation is adopted instead of released');
    $equal($before,$balance(),'Legacy gateway allocation receives no second return');
    $equal(0,ReceiptReturns::outstanding($saved),'Adopted native reversal completes the allocation');
});
$case('open hold and later win',function()use($make,$reserve,$observe,$refunds,$equal,$balance,$user):void{
    $op=$make('hold');$a=$reserve($op);$before=$balance();$observe($op,'open',$a['amount_minor']);
    $refunds->creditRejected($op->id);$equal(null,$op->fresh()->funding['receipt_return_results'][$a['key']]??null,'Open dispute keeps an unsent allocation reserved');
    $equal($before,$balance(),'Open hold pays nothing');$observe($op,'won',$a['amount_minor']);
    $refunds->creditRejected($op->id);$refunds->creditRejected($op->id);
    $equal($before+$a['amount_minor'],$balance(),'Won dispute releases to credit exactly once');
    DB::transaction(function()use($op,$equal):void{$l=HoldingLocks::take($op->domain);$equal([],RefundPayment::lateUncredited($op->fresh(),$l),'Released credit leaves no second late-credit entitlement');});
    $presenter=(new ReflectionClass(NativePresenter::class))->newInstanceWithoutConstructor();
    $equal(['credit','$'.PricingRule::decimal($a['amount_minor'])],(new ReflectionMethod(NativePresenter::class,'outcomeMoney'))->invoke($presenter,[$op->fresh()],Invoice::findOrFail($op->invoice_id)),'Native outcome reports a fully released credit');
});
$case('lost receipt releases without a credit notice',function()use($make,$reserve,$observe,$refunds,$equal,$balance):void{
    $op=$make('fully-lost');$a=$reserve($op);$before=$balance();$observe($op,'lost',$a['amount_minor']);
    $equal(null,$refunds->creditRejected($op->id),'Zero released credit produces no money-return notification');
    $equal('released',$op->fresh()->funding['receipt_return_results'][$a['key']]['state'],'Fully lost receipt releases its reservation');
    $equal(null,ReceiptReturns::summary($op->fresh()),'A release without credit never claims account credit was added');
    $equal($before,$balance(),'Fully lost receipt pays no credit');
});
$case('owner hold waits',function()use($make,$reserve,$refunds,$equal,$balance):void{
    $op=$make('owner-hold');$a=$reserve($op);$before=$balance();
    $id=DB::table('domains_obligation_alerts')->insertGetId(['alert_key'=>hash('sha256','release-owner-hold'),'domain_id'=>$op->domain_id,
        'generation'=>1,'code'=>'account_money_hold','operation_id'=>$op->id,'first_seen'=>1,'last_seen'=>1]);
    $refunds->creditRejected($op->id);$equal(null,$op->fresh()->funding['receipt_return_results'][$a['key']]??null,'Owner hold retains unsent reservation');
    $equal($before,$balance(),'Owner hold blocks released credit');DB::table('domains_obligation_alerts')->where('id',$id)->update(['resolved_at'=>2]);
    $refunds->creditRejected($op->id);$equal('refunded',$op->fresh()->funding['receipt_return_results'][$a['key']]['state'],'A released owner hold allows the reserved return');
});
$case('overdrawn release keeps repair',function()use($make,$reserve,$observe,$refunds,$equal,$balance):void{
    $op=$make('overdrawn');$a=$reserve($op);$before=$balance();$observe($op,'lost',$a['amount_minor']);
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded','transaction_id'=>'synthetic_external_negative']);
    $refunds->creditRejected($op->id);$refunds->creditRejected($op->id);
    $r=$op->fresh()->funding['receipt_return_results'][$a['key']]??[];
    $equal(['released',0],[$r['state']??null,$r['credit_minor']??null],'Overdraw releases the reservation without credit');
    $equal($before,$balance(),'Overdraw pays no money');
    $equal(true,DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Overdraw repair survives repeated sweeps');
});
$case('each result commits before the next send',function()use($make,$refunds,$staff,$equal):void{
    $op=$make('per-result');$minor=PricingRule::minor($op->amount);$invoice=Invoice::findOrFail($op->invoice_id);
    DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['amount'=>PricingRule::decimal(intdiv($minor,2))]);
    InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>PricingRule::decimal($minor-intdiv($minor,2)),'fee'=>'0.00','status'=>'succeeded',
        'gateway_id'=>InvoiceTransaction::where('invoice_id',$invoice->id)->first()->gateway_id,'transaction_id'=>'synthetic_second_'.$op->id]);
    MoneyReceiptGateway::$onAllocated=static function():void{MoneyReceiptGateway::$onStatus=static fn()=>throw new RuntimeException('Synthetic next receipt failure');};
    try{$refunds->refundHeld($staff,$op->id,'customer_cancelled');}catch(RuntimeException){}
    $a=$op->fresh()->funding['refund']['allocations'];
    $equal('refunded',$op->fresh()->funding['receipt_return_results'][$a[0]['key']]['state']??null,'First receipt result is durable before the next receipt fails');
    $equal(null,$op->fresh()->funding['receipt_return_results'][$a[1]['key']]??null,'Next unsent receipt stays reserved');
    $refunds->creditRejected($op->id);$equal(0,ReceiptReturns::outstanding($op->fresh()),'Retry finishes remaining receipt');
});
$case('release credit and result are atomic',function()use($make,$reserve,$release,$equal,$balance,$check):void{
    $op=$make('atomic');$a=$reserve($op);$before=$balance();$fail=true;
    Operation::updating(static function($row)use($op,&$fail):void{if($fail&&$row->id===$op->id&&isset($row->funding['receipt_return_results'])){throw new DomainException('Synthetic released result failure');}});
    $refused=false;try{$release($op,$a);}catch(DomainException){$refused=true;}$fail=false;
    $check($refused,'Failed result save refuses release');$equal($before,$balance(),'Failed released result rolls credit back');
    $equal(null,$op->fresh()->funding['receipt_return_results'][$a['key']]??null,'Failed release retains the reservation');
    $release($op,$a);$release($op,$a);$equal($before+$a['amount_minor'],$balance(),'Atomic release retry pays once');
});
$case('sending never releases',function()use($make,$refunds,$staff,$release,$equal,$balance):void{
    $op=$make('sending');ReleaseGateway::$beforeSend=true;
    try{$refunds->refundHeld($staff,$op->id,'customer_cancelled');}catch(RuntimeException){}
    $a=$op->fresh()->funding['refund']['allocations'][0];$before=$balance();$retained=$op->fresh()->funding;
    $release($op,$a);$equal($retained,$op->fresh()->funding,'Sending allocation is never released');$equal($before,$balance(),'Sending release attempt writes no credit');
});
// Resume phases are money decisions on the order's invoice: contention retries once, then queues the order with no send or repair.
$busy=['armed'=>null,'fails'=>0,'tries'=>0];
Operation::updating(static function($row)use(&$busy):void{
    if($busy['armed']!==$row->id||!in_array('sending',array_column($row->funding['receipt_return_results']??[],'state'),true)){return;}
    if(++$busy['tries']<=$busy['fails']){$cause=new PDOException('Synthetic deadlock');$cause->errorInfo=['40001',1213,'Synthetic'];throw new \Illuminate\Database\QueryException('sqlite','synthetic send intent',[],$cause);}
});
$invoiceReads=['on'=>false,'ids'=>[]];
\Illuminate\Support\Facades\Event::listen('eloquent.retrieved: '.Invoice::class,static function($invoice)use(&$invoiceReads):void{if($invoiceReads['on']){$invoiceReads['ids'][(int)$invoice->id]=true;}});
foreach([1,2] as $fails){$case('resume contention '.$fails,function()use($fails,$make,$reserve,$refunds,$equal,&$busy,&$invoiceReads):void{
    \Paymenter\Extensions\Servers\Domains\Services\MoneyWorkSchema::install();
    $op=$make('resume-busy-'.$fails);$a=$reserve($op);$sends=MoneyReceiptGateway::$sends;
    $work=static fn()=>DB::table('domains_money_work')->where('subject_id',$op->id)->get(['kind','revision'])->map(fn($r)=>(array)$r)->all();
    // The crash after the plan already opened a repair; a deferral leaves it exactly as it was, and the finished return resolves it.
    $repair=static fn()=>DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->get()->map(fn($r)=>(array)$r)->all();
    $repaired=static fn()=>DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->exists();$crashed=$repair();
    $busy=['armed'=>$op->id,'fails'=>$fails,'tries'=>0];$invoiceReads=['on'=>$fails===1,'ids'=>[]];
    try{$refunds->creditRejected($op->id);}finally{$busy['armed']=null;$invoiceReads['on']=false;}
    $equal(2,$busy['tries'],'The send intent is retried once after contention: '.$fails);
    if($fails===1){
        $equal(['refunded',$sends+1,[],false],[$op->fresh()->funding['receipt_return_results'][$a['key']]['state']??null,MoneyReceiptGateway::$sends,$work(),$repaired()],'One contention: the retry sends once and queues nothing');
        $equal([(int)$op->invoice_id],array_keys($invoiceReads['ids']),'Resume reads only the order invoice, not the owner\'s other invoices');
        return;
    }
    $equal([null,$sends,[['kind'=>'refund_rejected','revision'=>1]],$crashed],[$op->fresh()->funding['receipt_return_results'][$a['key']]??null,MoneyReceiptGateway::$sends,$work(),$repair()],
        'Two contentions: nothing is sent or saved, the order is queued under its kind and needs no repair');
    $refunds->creditRejected($op->id);
    $equal(['refunded',$sends+1,false],[$op->fresh()->funding['receipt_return_results'][$a['key']]['state']??null,MoneyReceiptGateway::$sends,$repaired()],'The queued resume sends once on its next run');
    DB::table('domains_money_work')->where('subject_id',$op->id)->delete();
});}
// A staff credit that defers in resume uses up its review, stays queued as automatic work and tells staff to try again.
$case('staff credit resume contention',function()use($make,$reserve,$refunds,$equal,$clock,&$busy):void{
    \Paymenter\Extensions\Servers\Domains\Services\MoneyWorkSchema::install();
    $op=$make('resume-busy-staff');$a=$reserve($op);$sends=MoneyReceiptGateway::$sends;
    $retry=commerceUser();$retry->update(['role_id'=>Role::create(['name'=>'Release staff retry','permissions'=>['admin.domains.view','admin.domains.manage','admin.invoices.update',StaffOperationReview::permission('credit')]])->id]);
    $review=new StaffOperationReview((int)$retry->id,$op->domain_id,$op->id,'credit',StaffOperationReview::ticket((int)$retry->id,$op->domain_id,$op->id,'credit',
        StaffOperationReview::stamp($op->fresh(),$op->domain->fresh()),$clock),'Reviewed current evidence',$clock);
    $busy=['armed'=>$op->id,'fails'=>2,'tries'=>0];$refused=null;
    try{$refunds->creditRejected($op->id,$review);}catch(StaffReviewRefused $e){$refused=$e->getMessage();}finally{$busy['armed']=null;}
    $work=DB::table('domains_money_work')->where('subject_id',$op->id)->pluck('kind')->all();
    $equal([MoneyDecisions::BUSY,null,$sends,['refund_rejected']],[$refused,$op->fresh()->funding['receipt_return_results'][$a['key']]??null,MoneyReceiptGateway::$sends,$work],
        'Two contentions on a staff credit: staff are told to try again, nothing is sent and the order stays queued');
    $refunds->creditRejected($op->id);
    $equal(['refunded',$sends+1],[$op->fresh()->funding['receipt_return_results'][$a['key']]['state']??null,MoneyReceiptGateway::$sends],'The queued resume sends once on its next run');
    DB::table('domains_money_work')->where('subject_id',$op->id)->delete();
});
foreach(['register','transfer'] as $action){$case('zero held '.$action,function()use($make,$action,$observe,$refunds,$equal,$balance):void{
    $op=$make('zero-'.$action,$action);$op->update(['state'=>'rejected','attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent','disposition'=>'rejected',
        'rejection_basis'=>'definitely_unsent','terminal'=>['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation]]]);
    $observe($op,'lost',PricingRule::minor($op->amount));$before=$balance();$refunds->creditRejected($op->id);
    $equal($action==='transfer'?'transfer_rejected':'registration_rejected',$op->domain->fresh()->status,'Zero-held rejection closes its domain');
    $equal('cancelled',Service::findOrFail($op->service_id)->status,'Zero-held rejection cancels its native service');
    $equal(false,isset($op->fresh()->funding['refund']),'Zero-held rejection writes no refund plan');$equal($before,$balance(),'Zero-held closure writes no credit');
});}
expect($failures===[],'Release regressions: '.json_encode($failures,JSON_THROW_ON_ERROR));
echo "Refund release, resend, reader coverage and zero-held closure passed\n";$nativeSuccessful=true;
