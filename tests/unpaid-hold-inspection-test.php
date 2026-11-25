<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';

use App\Models\{Credit, InvoiceTransaction};
use Illuminate\Support\Facades\{Cache, DB, Log};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{PayCheck, PricingRule, Refunds};
use Paymenter\Extensions\Servers\Domains\Money\Returns\RefundPayment;

$refunds=new Refunds($clock);$account=Account::where('server_id',$tlds[0]->server_id)->sole();
$balance=static fn():int=>PricingRule::minor((string)(Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));
$alerts=static fn($op):int=>DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','acquisition_late_money')->count();
$shut=static fn($op)=>$retirement->closeUnpaid((int)$op->invoice_id,PayCheck::notice('expired'));
$pay=static fn($op)=>InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
$sql=static fn(string $query):string=>strtolower(str_replace(['"','`','[',']'],'',$query));

// An error in the error handler must not discard earlier inspections or stop later invoices.
$repairFailure=null;
DB::connection()->beforeExecuting(static function($query,$bindings)use(&$repairFailure,$sql):void {
    if($repairFailure&&str_starts_with($sql($query),'insert into domains_obligation_alerts')&&in_array('refund_repair',$bindings,true)){throw $repairFailure;}
});
foreach(['repair','logger'] as $fault){
    $ops=[];
    foreach(['earlier','broken','later'] as $position){
        $op=$new('inspection-'.$fault.'-'.$position.'-placeholder.com',false);$shut($op);$pay($op);$ops[]=$op;
        if($position==='broken'){$op->update(['started_at'=>$clock->now()]);}
        else{DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);}
    }
    $before=$balance();$failure=new RuntimeException('Synthetic '.$fault.' unavailable');$caught=null;$log=Log::getFacadeRoot();
    if($fault==='repair'){$repairFailure=$failure;}
    else{
        $mock=Mockery::mock(Psr\Log\LoggerInterface::class);
        $mock->shouldReceive('warning')->andThrow($failure);Log::swap($mock);
    }
    Cache::forget('domains.closed-credit-cursor.'.$account->server_id);
    try{$refunds->creditRetired($account);}catch(Throwable $e){$caught=$e;}
    finally{$repairFailure=null;Log::swap($log);}
    same(1,$alerts($ops[0]),$fault.': earlier queued domain is inspected despite the later handler failure');
    same(1,$alerts($ops[2]),$fault.': a bad invoice cannot cancel later inspections');
    same($failure,$caught,$fault.': the original handler failure surfaces after the drain');
    same($before,$balance(),$fault.': failed proof and Paid invoices authorize no credit');
}
echo "Deferred inspection failures drained without losing alerts.\n";

// The peer collection is an observation only, even when it happens to be current.
$guarded=false;
try{DB::transaction(static fn()=>RefundPayment::lateCredited($ops[0],collect([$ops[0]])));}
catch(LogicException){$guarded=true;}
expect($guarded,'Observed peers are refused inside a transaction');
same(0,DB::transactionLevel(),'Observed-peer refusal rolls back its transaction');

// Change persisted money after the batched SELECT has returned its rows, before the locked reread.
// This is a sequential interleaving in SQLite, not evidence of concurrent database locking.
$race=null;$raceInvoice=null;$races=0;$lockedReads=0;
DB::listen(static function($query)use(&$race,&$raceInvoice,&$races,&$lockedReads,$sql):void {
    $text=$sql($query->sql);
    if($raceInvoice&&DB::transactionLevel()>0&&str_starts_with($text,'select')&&str_contains($text,' from invoice_transactions ')){$lockedReads++;}
    if($race&&DB::transactionLevel()===0&&preg_match('/^select .* from domains_operations where invoice_id in \(/',$text)
        &&in_array($raceInvoice,$query->bindings)){
        $change=$race;$race=null;$races++;$change();
    }
});
foreach(['payment','peer_paid','peer_released'] as $change){
    $name=str_replace('_','-',$change);$cart=commerceCart($user);
    foreach(['one','two'] as $part){commerceAdd($cart,$product,'inspection-'.$name.'-'.$part.'-placeholder.com');}
    commerceCheckout($user,$cart);
    $domain=Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain','inspection-'.$name.'-one-placeholder.com')->sole();
    $op=$domain->operations()->sole();$shut($op);
    $peer=Operation::where('invoice_id',$op->invoice_id)->where('id','!=',$op->id)->sole();$payment=$pay($op);
    $credit=Credit::firstOrCreate(['user_id'=>$user->id,'currency_code'=>'USD'],['amount'=>'0.00']);$before=$balance();
    $plan=['payments'=>[['id'=>(int)$payment->id,'amount_minor'=>100]],'amount'=>'1.00','currency'=>'USD',
        'to'=>'account_credit','credit_id'=>(int)$credit->id,'at'=>$clock->now()->format(DATE_ATOM)];
    $prior=$peer->funding??[];
    if($change==='peer_released'){
        $peer->update(['funding'=>[...$prior,'late_credits'=>[$plan]]]);$credit->update(['amount'=>PricingRule::decimal($before+100)]);
    }
    $raceInvoice=(int)$op->invoice_id;$reads=$lockedReads;$ran=$races;
    $race=static function()use($change,$payment,$peer,$prior,$plan,$credit,$before):void {
        if($change==='payment'){DB::table('invoice_transactions')->where('id',$payment->id)->update(['amount'=>'2.00']);}
        elseif($change==='peer_paid'){
            $peer->update(['funding'=>[...$prior,'late_credits'=>[$plan]]]);$credit->update(['amount'=>PricingRule::decimal($before+100)]);
        }else{$peer->update(['funding'=>$prior]);$credit->update(['amount'=>PricingRule::decimal($before)]);}
    };
    $retirement->inspect((int)$op->domain_id);
    same($ran+1,$races,$change.': change runs after the unlocked batched peer read');
    if($change!=='peer_released'){expect($lockedReads>$reads,$change.': inspection rereads native payments inside a transaction');}
    same($change==='payment'?1:0,$alerts($op),$change.': the locked inspection sees current peer coverage');
    Cache::forget('domains.closed-credit-cursor.'.$account->server_id);
    $refunds->creditRetired($account);
    same($before+($change==='payment'?200:100),$balance(),$change.': locked money reads return exactly the current uncredited funds');
    $refunds->creditRetired($account);
    same($before+($change==='payment'?200:100),$balance(),$change.': repeating the sweep cannot duplicate money');
    $done=RefundPayment::lateCredited($op->fresh());
    same([$payment->id=>$change==='payment'?200:100],$done,$change.': retained receipt shares match current money without duplication');
    $raceInvoice=null;
}
echo "Batched observations cannot authorize, skip or duplicate money.\n";
$nativeSuccessful=true;
