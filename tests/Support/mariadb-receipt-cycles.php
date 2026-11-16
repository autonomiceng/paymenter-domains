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
$waitForBlock=static function(int $requester,string $table)use($prefix):bool{
    $qualified='`'.DB::getDatabaseName().'`.`'.$prefix.$table.'`';
    return (int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w'
        .' JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id'
        .' JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id'
        .' WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=CONNECTION_ID() AND l.lock_table=?',[$requester,$qualified])->n>0;
};
$error=null;$value=null;
try{
    $value=DB::transaction(static function()use($phase,$case,$op,$first,$seed,$root,$name,$prefix,$signal,$wait,$ballast,$waitForBlock):bool{
        if($phase==='receipt'){
            // Core already owns B when its creating hook starts. Keep this transaction lighter than the other writer.
            Invoice::whereKey($op->invoice_id)->lockForUpdate()->firstOrFail();$signal('invoice-locked');$wait('runner-started');
            $other=(int)file_get_contents($root.'/'.$name.'-connection');
            $end=microtime(true)+20;
            while(true){
                if($waitForBlock($other,'invoices')){file_put_contents($root.'/'.$name.'-invoice-wait','proved');break;}
                if($case==='receipts'&&is_file($root.'/'.$name.'-second-owner')){break;}
                if(microtime(true)>$end){throw new RuntimeException('Neither the invoice wait nor receipt owner overlap was observed.');}usleep(200000);
            }
            $signal('primary-started');
            ExtensionHelper::addPayment((int)$op->invoice_id,null,'1.00','0.00','receipt-cycle-'.$name.'-b');
        }else{
            $wait('invoice-locked');$ballast();
            // A full scan obtains A before B; a second native receipt also arrives owning its distinct invoice A.
            Invoice::whereKey($first->invoice_id)->lockForUpdate()->firstOrFail();
            file_put_contents($root.'/'.$name.'-connection',(string)DB::selectOne('SELECT CONNECTION_ID() AS id')->id);$signal('runner-started');
            if($case==='full'){
                HoldingLocks::take($seed);
            }else{
                $armed=true;
                DB::listen(static function($q)use(&$armed,$prefix,$signal,$wait):void{
                    if($armed&&str_contains($q->sql,'from `'.$prefix.'users`')&&str_contains($q->sql,'for update')){
                        $armed=false;$signal('second-owner');$wait('primary-started');
                    }
                });
                ExtensionHelper::addPayment((int)$first->invoice_id,null,'1.00','0.00','receipt-cycle-'.$name.'-a');
            }
        }
        return true;
    });
}catch(Throwable $e){
    if(!MoneyDecisions::contention($e)){throw $e;}
    $error='contention';
}
$subject=$phase==='receipt'?$op:$first;
$count=InvoiceTransaction::where('invoice_id',$subject->invoice_id)->count();
$closed=$subject->fresh()->state==='rejected';
$complete=$value===true&&$error===null&&DB::transactionLevel()===0&&($phase==='runner'&&$case==='full'||$count===1&&$closed);
echo json_encode(['complete'=>$complete,'case'=>$case,'phase'=>$phase,'error'=>$error,'receipts'=>$count,'closed'=>$closed,'transaction_level'=>DB::transactionLevel()])."\n";
expect($complete,'Concurrent native receipts and full invoice scans must preserve every payment');$nativeSuccessful=true;
