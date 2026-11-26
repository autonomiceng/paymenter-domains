<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Two native connections, no provider calls.
// A native receipt on a cancelled renewal pauses inside core's payment transaction while another customer's full decision,
// or the same customer's checkout, runs. Domains takes no locks there, so both finish and the payment is always recorded;
// after commit the renewal closes and the payment returns as account credit.
declare(strict_types=1);
$runtime=getenv('RECEIPT_RUNTIME')?:dirname(__DIR__,2);
$deferDomainsBootstrap=true;require $runtime.'/tests/Support/mariadb-bootstrap.php';
require $runtime.'/tests/Support/commerce.php';require $runtime.'/tests/Support/native-commerce.php';
PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());
use App\Helpers\ExtensionHelper;
use App\Models\{Credit,Invoice,InvoiceTransaction,User};
use Illuminate\Support\Facades\{Artisan,DB,Queue,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationSchema;
use Paymenter\Extensions\Servers\Domains\Services\{FreshTransaction,FundsWaits,HoldingLocks,MoneyDecisions,PricingRule};
$root=(string)getenv('RECEIPT_BARRIERS');expect(is_dir($root),'Private barriers required');
$phase=getenv('RECEIPT_PHASE');$case=(string)getenv('RECEIPT_CASE');$name=(string)getenv('RECEIPT_NAME');$prefix=(string)getenv('SCHEMA_PREFIX');
$signal=static fn(string $step)=>file_put_contents($root.'/'.$name.'-'.$step,'ready');
$wait=static function(string $step)use($root,$name):void {
    $end=mariadbClock()+25;
    while(!is_file($root.'/'.$name.'-'.$step)){if(mariadbClock()>$end){throw new RuntimeException('Barrier timeout: '.$step);}usleep(10000);}
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
    // The other-customer invoices are adjacent. Each race has a fresh renewal with no terminal history.
    require $runtime.'/tests/Support/receipt-renewal-seed.php';
    foreach(['other-receipt','other-peer','checkout-receipt']as$kind){
        $customer=commerceUser();
        $cart=commerceCart($customer);commerceAdd($cart,$native->product($tlds[0],'register'),placeholderComFixtureName('receipt-'.$kind.'-placeholder.com'));
        commerceCheckout($customer,$cart)->assertHasNoErrors();
        $op=Domain::where('domain',placeholderComFixtureName('receipt-'.$kind.'-placeholder.com'))->sole()->operations()->sole();
        receiptRenewalSeed($op);
        if($kind!=='other-peer'){DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'cancelled']);}
        $ids[$kind]=(int)$op->id;
    }
    file_put_contents($root.'/ids.json',json_encode($ids,JSON_THROW_ON_ERROR));
    echo "Seeded receipt lock fixtures.\n";$nativeSuccessful=true;return;
}
$ids=json_decode(file_get_contents($root.'/ids.json'),true,512,JSON_THROW_ON_ERROR);
$op=Operation::findOrFail($ids[$case.'-receipt']);$seed=$op->domain()->firstOrFail();
require $runtime.'/tests/Support/mariadb-commerce.php';
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock));
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
// Checkout subclasses arrange synthetic offers; locking reads verify the installed adapters instead.
foreach(['CartAdmission','CheckoutEligibility','Storefront','PayCheck','NativeCatalogue','StaffSettingsWrites','SellerPolicies','PriceCatalogue']as$type){
    app()->offsetUnset('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$type);
}
$runtimePorts=new Paymenter\Extensions\Servers\Domains\Services\RuntimePorts;
app()->instance(Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class,$runtimePorts);
$runtimePorts->registerInitialManagedRecordObservations(Paymenter\Extensions\Servers\Domains\Services\Runtime::initialManagedRecordObservations(...));
DB::statement('SET SESSION innodb_lock_wait_timeout=20');
// Whether $requester waits on any lock this connection holds.
$blocks=static fn(int $requester):bool=>(int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w'
    .' JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id'
    .' WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=CONNECTION_ID()',[$requester])->n>0;
$contention=static function(Throwable $e):int {
    if(!MoneyDecisions::contention($e)){throw $e;}
    for($cause=$e;$cause!==null;$cause=$cause->getPrevious()){if($cause instanceof PDOException&&isset($cause->errorInfo[1])){return (int)$cause->errorInfo[1];}}
    return 0;
};
if($phase==='receipt'){
    $cash=static fn()=>PricingRule::minor((string)(Credit::where('user_id',$seed->user_id)->where('currency_code','USD')->value('amount')??'0.00'));$before=$cash();
    $inCore=false;$ownerLocks=0;$domainsLocks=0;$armed=true;$waited=false;
    DB::listen(static function($q)use(&$inCore,&$ownerLocks,&$domainsLocks,&$armed,&$waited,$prefix,$signal,$wait,$root,$name,$blocks):void{
        if($inCore&&preg_match('/\b(?:for update|lock in share mode|for share)\b/i',$q->sql)){
            if(str_contains($q->sql,'from `'.$prefix.'users`')){$ownerLocks++;}
            foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)as$frame){
                if(str_starts_with($frame['class']??'','Paymenter\\Extensions\\Servers\\Domains\\')){$domainsLocks++;break;}
            }
        }
        if(!$armed||!str_starts_with($q->sql,'insert into `'.$prefix.'invoice_transactions`')){return;}
        // Core holds the invoice and its new payment row. Pause until the other writer waits on us or finishes.
        $armed=false;DB::afterCommit(static function()use(&$inCore):void {$inCore=false;});
        $signal('receipt-inserted');$wait('runner-started');
        $runner=(int)file_get_contents($root.'/'.$name.'-connection');$end=mariadbClock()+20;
        while(!is_file($root.'/'.$name.'-runner-done')){
            if($blocks($runner)){$waited=true;break;}
            if(mariadbClock()>$end){throw new RuntimeException('The other writer neither waited nor finished.');}usleep(100000);
        }
    });
    $error=null;$inCore=true;
    // Core opens its own outermost payment transaction, as from a gateway callback.
    try{ExtensionHelper::addPayment((int)$op->invoice_id,null,'1.00','0.00','receipt-lock-'.$name);}catch(Throwable $e){$error=$contention($e);}
    $receipts=InvoiceTransaction::where('invoice_id',$op->invoice_id)->where('status','succeeded')->count();
    $closed=$op->fresh()->state==='rejected';$cancelled=Invoice::whereKey($op->invoice_id)->value('status')==='cancelled';$credited=$cash()-$before;
    $repeatRefused=false;
    if($closed){
        try{
            FreshTransaction::run(static function()use($seed,$op,$clock):void{
                $locks=HoldingLocks::take($seed);
                FundsWaits::closeRenewal($locks['operations']->firstWhere('id',$op->id),$locks,$clock->now());
            });
        }catch(DomainException){$repeatRefused=true;}
    }
    $terminalRows=DB::table(OperationSchema::EVIDENCE)->where(['operation_id'=>$op->id,'kind'=>'terminal'])->count();
    $complete=!$armed&&$error===null&&$receipts===1&&$cancelled&&$closed&&$credited===100&&$repeatRefused&&$terminalRows===1
        &&$ownerLocks===0&&$domainsLocks===0&&DB::transactionLevel()===0;
    echo json_encode(['complete'=>$complete,'case'=>$case,'phase'=>$phase,'error'=>$error,'owner_locks'=>$ownerLocks,'domains_locks'=>$domainsLocks,
        'receipts'=>$receipts,'cancelled'=>$cancelled,'closed'=>$closed,'credited'=>$credited,'terminal_rows'=>$terminalRows,
        'repeat_closure_refused'=>$repeatRefused,'runner_waited'=>$waited,'transaction_level'=>DB::transactionLevel()])."\n";
    expect($complete,'The receipt is recorded, stays cancelled and returns once as account credit');$nativeSuccessful=true;return;
}
expect($phase==='runner','Known fixture phase');$wait('receipt-inserted');
file_put_contents($root.'/'.$name.'-connection',(string)DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$signal('runner-started');$error=null;$value=false;
try{
    if($case==='other'){
        // A full decision for another customer on the same registrar server, in a Domains writer transaction.
        $otherSeed=Operation::findOrFail($ids['other-peer'])->domain()->firstOrFail();
        expect((int)$otherSeed->user_id!==(int)$seed->user_id&&(int)$otherSeed->server_id===(int)$seed->server_id,'Another customer on the same server');
        $value=FreshTransaction::run(static fn():bool=>HoldingLocks::take($otherSeed)['domain']->id===$otherSeed->id);
    }else{
        expect($case==='checkout','Known receipt case');
        // The receipt's customer checks out a new domain: core locks the customer, then inserts the order and its invoice.
        $customer=User::findOrFail($seed->user_id);$domain=placeholderComFixtureName('receipt-checkout-'.substr(md5($name),0,8).'-placeholder.com');
        $cart=commerceCart($customer);commerceAdd($cart,$native->product(Tld::where('registrar_key','enom')->sole(),'register'),$domain);
        commerceCheckout($customer,$cart)->assertHasNoErrors();$value=Domain::where('domain',$domain)->count()===1;
    }
}catch(Throwable $e){$error=$contention($e);}
$signal('runner-done');
$complete=$value===true&&$error===null&&DB::transactionLevel()===0;
echo json_encode(['complete'=>$complete,'case'=>$case,'phase'=>$phase,'error'=>$error,'transaction_level'=>DB::transactionLevel()])."\n";
expect($complete,'The other writer finishes without a deadlock or lock wait timeout');$nativeSuccessful=true;
