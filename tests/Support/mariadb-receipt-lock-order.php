<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Two native connections, no provider calls.
declare(strict_types=1);
$runtime=getenv('RECEIPT_RUNTIME')?:dirname(__DIR__,2);
$deferDomainsBootstrap=true;require $runtime.'/tests/Support/mariadb-bootstrap.php';
require $runtime.'/tests/Support/commerce.php';require $runtime.'/tests/Support/native-commerce.php';
PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());
use App\Helpers\ExtensionHelper;
use App\Models\{Invoice,InvoiceTransaction};
use Illuminate\Support\Facades\{Artisan,DB,Queue,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{InvoiceScope,MoneyDecisions,MoneyOutcome,MoneyRequest,MoneyScope};
$root=(string)getenv('RECEIPT_BARRIERS');expect(is_dir($root),'Private barriers required');
$phase=getenv('RECEIPT_PHASE');$case=(string)getenv('RECEIPT_CASE');$name=(string)getenv('RECEIPT_NAME');$prefix=(string)getenv('SCHEMA_PREFIX');
$signal=static fn(string $step)=>file_put_contents($root.'/'.$name.'-'.$step,'ready');
$wait=static function(string $step)use($root,$name):void {
    $end=microtime(true)+25;
    while(!is_file($root.'/'.$name.'-'.$step)){if(microtime(true)>$end){throw new RuntimeException('Barrier timeout: '.$step);}usleep(10000);}
};
if($phase==='seed'){
    Artisan::call('migrate',['--force'=>true]);
    if($prefix!==''){foreach(Schema::getTableListing(DB::connection()->getDatabaseName(),false)as$table){Schema::rename($table,$prefix.$table);}}
}
if($prefix!==''){config(['database.connections.mysql.prefix'=>$prefix]);DB::purge('mysql');Schema::clearResolvedInstance('db.schema');}
Queue::fake();
if($phase==='seed'){
    extract(commerceFixture(true,registrars:['enom']));$ids=[];
    require $runtime.'/tests/Support/mariadb-commerce.php';
    $customer=commerceUser();
    foreach(['scoped','widened']as$kind){
        $cart=commerceCart($customer);commerceAdd($cart,$native->product($tlds[0],'register'),placeholderComFixtureName('receipt-'.$kind.'-placeholder.com'));
        commerceCheckout($customer,$cart)->assertHasNoErrors();
        $op=Domain::where('domain',placeholderComFixtureName('receipt-'.$kind.'-placeholder.com'))->sole()->operations()->sole();
        // Retained unpaid renewal: its line is independent of the original service purchase.
        DB::table('invoice_items')->where('id',$op->invoice_item_id)->update(['reference_type'=>null,'reference_id'=>null,'price'=>$op->amount,'quantity'=>1]);
        DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'renew','state'=>'awaiting_payment']);
        $ids[$kind]=(int)$op->id;
    }
    // Another customer's retained renewal, checked out next so its invoice neighbours the widened one.
    $other=commerceUser();$cart=commerceCart($other);commerceAdd($cart,$native->product($tlds[0],'register'),placeholderComFixtureName('receipt-other-placeholder.com'));
    commerceCheckout($other,$cart)->assertHasNoErrors();
    $op=Domain::where('domain',placeholderComFixtureName('receipt-other-placeholder.com'))->sole()->operations()->sole();
    DB::table('invoice_items')->where('id',$op->invoice_item_id)->update(['reference_type'=>null,'reference_id'=>null,'price'=>$op->amount,'quantity'=>1]);
    DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'renew','state'=>'awaiting_payment']);
    $ids['other']=(int)$op->id;
    file_put_contents($root.'/ids.json',json_encode($ids,JSON_THROW_ON_ERROR));
    echo "Seeded receipt lock fixtures.\n";$nativeSuccessful=true;return;
}
$ids=json_decode(file_get_contents($root.'/ids.json'),true,512,JSON_THROW_ON_ERROR);
$cycle=in_array($case,['full','receipts','other'],true);
$op=Operation::findOrFail($ids[$cycle?'widened':$case]);$seed=$op->domain()->firstOrFail();
if($phase==='reset'){
    foreach($cycle?$ids:[$op->id]as$id){
        $row=Operation::findOrFail($id);
        DB::table('invoice_transactions')->where('invoice_id',$row->invoice_id)->delete();
        DB::table('invoices')->where('id',$row->invoice_id)->update(['status'=>'cancelled']);
        DB::table('domains_operations')->where('id',$id)->update(['state'=>'awaiting_payment','safe_code'=>null,'attempt'=>null,'policy'=>null,'cost'=>null,'funding'=>null,'completed_at'=>null]);
    }
    echo "Reset retained unpaid renewal.\n";$nativeSuccessful=true;return;
}
require $runtime.'/tests/Support/mariadb-commerce.php';
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock));
// Checkout subclasses arrange synthetic offers; locking reads verify the installed adapters instead.
foreach(['CartAdmission','CheckoutEligibility','Storefront','PayCheck','NativeCatalogue','StaffSettingsWrites','SellerPolicies','PriceCatalogue']as$type){
    app()->offsetUnset('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$type);
}
$runtimePorts=new Paymenter\Extensions\Servers\Domains\Services\RuntimePorts;
app()->instance(Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class,$runtimePorts);
$runtimePorts->registerInitialManagedRecordObservations(Paymenter\Extensions\Servers\Domains\Services\Runtime::initialManagedRecordObservations(...));
DB::statement('SET SESSION innodb_lock_wait_timeout=20');
if($cycle){require __DIR__.'/mariadb-receipt-cycles.php';return;}
if($phase==='receipt'){
    DB::beginTransaction();
    // Weight only this native transaction so a reversed-order runner is the deadlock victim.
    foreach(array_chunk(range(1,300),100)as$chunk){DB::table('jobs')->insert(array_map(static fn($i)=>['queue'=>'receipt-ballast','payload'=>'{}','attempts'=>0,'available_at'=>1,'created_at'=>1],$chunk));}
    Invoice::whereKey($op->invoice_id)->lockForUpdate()->firstOrFail();$signal('invoice-locked');$wait('runner-started');
    $runner=(int)file_get_contents($root.'/'.$name.'-connection');
    $table='`'.DB::getDatabaseName().'`.`'.DB::getTablePrefix().'invoices`';
    $end=microtime(true)+20;
    do{
        $blocked=(int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w'
            .' JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id'
            .' JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id'
            .' WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=CONNECTION_ID() AND l.lock_table=?',[$runner,$table])->n;
        if($blocked){break;}if(microtime(true)>$end){throw new RuntimeException('Runner did not wait on the native invoice.');}usleep(200000);
    }while(true);
    file_put_contents($root.'/'.$name.'-invoice-wait','proved');
    // Use core itself, including its receipt hook and nested transaction.
    ExtensionHelper::addPayment((int)$op->invoice_id,null,'1.00','0.00','receipt-lock-'.$name);
    DB::commit();
    expect($op->fresh()->state==='rejected','The native receipt closes unpaid renewal authority');
    expect(InvoiceTransaction::where('invoice_id',$op->invoice_id)->count()===1,'The real native receipt commits exactly once');
    echo json_encode(['complete'=>true,'phase'=>$phase])."\n";$nativeSuccessful=true;return;
}
expect($phase==='runner','Known fixture phase');$wait('invoice-locked');
file_put_contents($root.'/'.$name.'-connection',(string)DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$ownerReads=0;
DB::listen(static function($q)use(&$ownerReads,$prefix):void{if(str_contains($q->sql,'from `'.$prefix.'users`')&&str_contains($q->sql,'for update')){$ownerReads++;}});
$signal('runner-started');
$result=MoneyDecisions::run(new MoneyRequest($seed,scope:MoneyScope::Invoice,invoiceId:(int)$op->invoice_id,retryRecord:'receipt_regression'),static function(array $l)use($case,$op):int {
    if($case==='widened'){InvoiceScope::requireFull($l);}
    expect($l['operations']->firstWhere('id',$op->id)?->state==='rejected','Runner reads the receipt closure after its invoice wait');
    expect($l['transactions']->where('invoice_id',$op->invoice_id)->count()===1,'Runner reads the committed receipt');
    return (int)$op->id;
});
$complete=$result->outcome===MoneyOutcome::Applied&&$result->value===$op->id&&$ownerReads===($case==='widened'?2:1)&&DB::transactionLevel()===0;
echo json_encode(['complete'=>$complete,'case'=>$case,'owner_attempts'=>$ownerReads,'outcome'=>$result->outcome->name,'transaction_level'=>DB::transactionLevel()])."\n";
expect($complete,'Native receipt and runner finish without a deadlock retry');$nativeSuccessful=true;
