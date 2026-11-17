<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Shares the installed receipt fixture and its two connections.
declare(strict_types=1);
use App\Helpers\ExtensionHelper;
use App\Models\{Invoice,InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,MoneyDecisions};
$first=Operation::findOrFail($ids['scoped']);
expect($first->invoice_id<$op->invoice_id,'The receipt target is the non-lowest owner invoice');
expect($first->domain->user_id===$seed->user_id,'Both invoices belong to the same owner');
$ballast=static function():void{
    foreach(array_chunk(range(1,300),100)as$chunk){DB::table('jobs')->insert(array_map(static fn($i)=>['queue'=>'receipt-cycle-ballast','payload'=>'{}','attempts'=>0,'available_at'=>1,'created_at'=>1],$chunk));}
};
// Whether $requester waits on a lock this connection holds, on $table or on any table.
$waitForBlock=static function(int $requester,?string $table=null)use($prefix):bool{
    $qualified=$table===null?null:'`'.DB::getDatabaseName().'`.`'.$prefix.$table.'`';
    return (int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w'
        .' JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id'
        .' JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id'
        .' WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=CONNECTION_ID() AND (? IS NULL OR l.lock_table=?)',[$requester,$qualified,$qualified])->n>0;
};
$otherSeed=Operation::findOrFail($ids['other'])->domain()->firstOrFail();
expect((int)$otherSeed->user_id!==(int)$seed->user_id&&(int)$otherSeed->server_id===(int)$seed->server_id,'The other decision is for another customer on the same registrar server');
$error=null;$value=null;$ownerLocks=0;
// Native receipts never lock the customer: only the invoice core holds and what hangs off it.
DB::listen(static function($q)use(&$ownerLocks,$prefix):void{if(str_contains($q->sql,'from `'.$prefix.'users`')&&str_contains($q->sql,'for update')){$ownerLocks++;}});
try{
    $value=DB::transaction(static function()use($phase,$case,$op,$first,$seed,$otherSeed,$root,$name,$prefix,$signal,$wait,$ballast,$waitForBlock):bool{
        if($phase==='receipt'&&$case==='other'){
            // Core's receipt pauses right after its payment-row scan, its worst point, until the other customer's
            // full decision waits on a row the receipt holds; then core inserts the payment.
            $armed=true;
            DB::listen(static function($q)use(&$armed,$prefix,$signal,$wait,$root,$name,$waitForBlock):void{
                if(!$armed||!str_contains($q->sql,'from `'.$prefix.'invoice_transactions`')||!str_contains($q->sql,'for update')){return;}
                $armed=false;$signal('receipt-scanned');$wait('runner-started');
                $other=(int)file_get_contents($root.'/'.$name.'-connection');$end=microtime(true)+20;
                while(!$waitForBlock($other)){if(microtime(true)>$end){throw new RuntimeException('The other decision did not wait on the receipt.');}usleep(200000);}
                file_put_contents($root.'/'.$name.'-other-wait','proved');
            });
            ExtensionHelper::addPayment((int)$op->invoice_id,null,'1.00','0.00','receipt-cycle-'.$name.'-b');
            if($armed){throw new RuntimeException('The receipt never scanned its payment rows.');}
        }elseif($phase==='receipt'){
            // Core already owns B when its creating hook starts. Keep this transaction lighter than the other writer.
            Invoice::whereKey($op->invoice_id)->lockForUpdate()->firstOrFail();$signal('invoice-locked');$wait('runner-started');
            $other=(int)file_get_contents($root.'/'.$name.'-connection');
            $end=microtime(true)+20;
            while(true){
                if($waitForBlock($other,'invoices')){file_put_contents($root.'/'.$name.'-invoice-wait','proved');break;}
                if($case==='receipts'&&is_file($root.'/'.$name.'-second-hook')){break;}
                if(microtime(true)>$end){throw new RuntimeException('Neither the invoice wait nor the receipt overlap was observed.');}usleep(200000);
            }
            $signal('primary-started');
            ExtensionHelper::addPayment((int)$op->invoice_id,null,'1.00','0.00','receipt-cycle-'.$name.'-b');
        }else{
            $wait($case==='other'?'receipt-scanned':'invoice-locked');$ballast();
            file_put_contents($root.'/'.$name.'-connection',(string)DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
            if($case==='other'){
                // A full decision for another customer whose invoice neighbours the receipt's.
                $signal('runner-started');HoldingLocks::take($otherSeed);
            }elseif($case==='full'){
                // A full decision locks the customer, then invoice A, then waits on B.
                $signal('runner-started');HoldingLocks::take($seed);
            }else{
                // A second native receipt arrives owning its distinct invoice A and pauses inside its hook
                // after its payment-row scan, just before its own receipt insert.
                Invoice::whereKey($first->invoice_id)->lockForUpdate()->firstOrFail();$signal('runner-started');
                $armed=true;
                DB::listen(static function($q)use(&$armed,$prefix,$signal,$wait):void{
                    if($armed&&str_contains($q->sql,'from `'.$prefix.'invoice_transactions`')&&str_contains($q->sql,'for update')){
                        $armed=false;$signal('second-hook');$wait('primary-started');
                    }
                });
                ExtensionHelper::addPayment((int)$first->invoice_id,null,'1.00','0.00','receipt-cycle-'.$name.'-a');
            }
        }
        return true;
    });
}catch(Throwable $e){
    if(!MoneyDecisions::contention($e)){throw $e;}
    for($cause=$e;$cause!==null;$cause=$cause->getPrevious()){
        if($cause instanceof PDOException&&isset($cause->errorInfo[1])){$error=(int)$cause->errorInfo[1];break;}
    }
    $error??=0;
}
$subject=$phase==='receipt'?$op:$first;
$count=InvoiceTransaction::where('invoice_id',$subject->invoice_id)->count();
$closed=$subject->fresh()->state==='rejected';
$complete=$value===true&&$error===null&&DB::transactionLevel()===0&&($phase==='runner'&&in_array($case,['full','other'],true)||$count===1&&$closed);
echo json_encode(['complete'=>$complete,'case'=>$case,'phase'=>$phase,'error'=>$error,'owner_locks'=>$ownerLocks,'receipts'=>$count,'closed'=>$closed,'transaction_level'=>DB::transactionLevel()])."\n";
expect($complete,'Concurrent native receipts and full invoice scans must preserve every payment');$nativeSuccessful=true;
