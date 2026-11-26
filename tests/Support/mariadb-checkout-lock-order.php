<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Two native processes, no provider calls.
// A native checkout holds its customer row, as core Cart does before it inserts the invoice. A full money decision
// (Record answer) and contact recovery then wait on that customer row and never hold an invoice range the checkout needs.
declare(strict_types=1);
$deferDomainsBootstrap=true;require __DIR__.'/mariadb-bootstrap.php';
require __DIR__.'/commerce.php';require __DIR__.'/native-commerce.php';
PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());
use App\Models\{Invoice,Role,User};
use Illuminate\Support\Facades\{Artisan,DB,Event,Queue,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery,MoneyDecisions,RegistrarAnswers,StaffSettingsReview as Review};
$root=(string)getenv('CHECKOUT_BARRIERS');expect(is_dir($root),'Private barriers required');
$phase=getenv('CHECKOUT_PHASE');$case=(string)getenv('CHECKOUT_CASE');$prefix=(string)getenv('SCHEMA_PREFIX');
$signal=static fn(string $step)=>file_put_contents($root.'/'.$case.'-'.$step,'ready');
$wait=static function(string $step)use($root,$case):void {
    $end=mariadbClock()+25;while(!is_file($root.'/'.$case.'-'.$step)){if(mariadbClock()>$end){throw new RuntimeException('Barrier timeout: '.$step);}usleep(10000);}
};
if($phase==='seed'){
    Artisan::call('migrate',['--force'=>true]);
    if($prefix!==''){foreach(Schema::getTableListing(DB::connection()->getDatabaseName(),false)as$table){Schema::rename($table,$prefix.$table);}}
}
if($prefix!==''){config(['database.connections.mysql.prefix'=>$prefix]);DB::purge('mysql');Schema::clearResolvedInstance('db.schema');}
Queue::fake();
if($phase==='seed'){
    extract(commerceFixture(true,registrars:['enom']));
    require __DIR__.'/mariadb-commerce.php';
    // The customer already has a domain and its invoice, so a full decision locks a real owner invoice range.
    $customer=commerceUser();$cart=commerceCart($customer);
    commerceAdd($cart,$native->product(Tld::where('registrar_key','enom')->sole(),'register'),placeholderComFixtureName('checkout-lock-held-placeholder.com'));
    commerceCheckout($customer,$cart)->assertHasNoErrors();
    $op=Domain::where('domain',placeholderComFixtureName('checkout-lock-held-placeholder.com'))->sole()->operations()->sole();
    $staff=commerceUser();$role=Role::create(['name'=>'Registrar answers','permissions'=>['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer','admin.domains.manage','admin.invoices.update']]);
    $staff->update(['role_id'=>$role->id]);
    file_put_contents($root.'/ids.json',json_encode(['customer'=>$customer->id,'staff'=>$staff->id,'operation'=>$op->id],JSON_THROW_ON_ERROR));
    echo "Seeded checkout lock fixtures.\n";$nativeSuccessful=true;return;
}
$ids=json_decode(file_get_contents($root.'/ids.json'),true,512,JSON_THROW_ON_ERROR);$customer=User::findOrFail($ids['customer']);
require __DIR__.'/mariadb-commerce.php';
DB::statement('SET SESSION innodb_lock_wait_timeout=20');
$waiting=static function(int $requester,string $table):int {
    $qualified='`'.DB::getDatabaseName().'`.`'.DB::getTablePrefix().$table.'`';
    return (int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w'
        .' JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id'
        .' JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id'
        .' WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=CONNECTION_ID() AND l.lock_table=?',[$requester,$qualified])->n;
};
$name=placeholderComFixtureName('checkout-lock-'.$case.'-placeholder.com');
if($phase==='checkout'){
    $before=Invoice::where('user_id',$customer->id)->count();
    $cart=commerceCart($customer);commerceAdd($cart,$native->product(Tld::where('registrar_key','enom')->sole(),'register'),$name);
    // Core Cart has locked the customer row and is about to insert the order and its invoice.
    Event::listen(\App\Events\Order\Creating::class,static function()use($signal,$wait,$waiting,$root,$case):void {
        $signal('customer-locked');$wait('writer-started');
        $writer=(int)file_get_contents($root.'/'.$case.'-writer-connection');$end=mariadbClock()+20;
        while($waiting($writer,'users')===0){if(mariadbClock()>$end){throw new RuntimeException('The writer did not wait on the checkout customer row.');}usleep(200000);}
        file_put_contents($root.'/'.$case.'-customer-wait','proved');
    });
    commerceCheckout($customer,$cart)->assertHasNoErrors();
    same(1,Domain::where('domain',$name)->count(),'Checkout commits its holding');
    same($before+1,Invoice::where('user_id',$customer->id)->count(),'Checkout commits its invoice');
    echo json_encode(['complete'=>true,'phase'=>$phase,'case'=>$case])."\n";$nativeSuccessful=true;return;
}
expect($phase==='writer','Known fixture phase');$wait('customer-locked');
file_put_contents($root.'/'.$case.'-writer-connection',(string)DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$locks=[];
DB::listen(static function($q)use(&$locks,$prefix):void{
    if(str_contains($q->sql,'for update')&&preg_match('/ from `'.preg_quote($prefix,'/').'([a-z_]+)`/',$q->sql,$m)){$locks[]=$m[1];}
});
$signal('writer-started');
$op=Operation::findOrFail($ids['operation']);$error=null;$refusal=null;
try{
    if($case==='decision'){
        // Record answer: the full decision takes the customer, then every customer invoice. This fixture's request is
        // not answerable, so the writer refuses after its locks; the lock order under contention is what this case proves.
        $staff=(int)$ids['staff'];$stamp=Review::stamp($op->server_id,null);
        $review=new Review($staff,$op->server_id,null,'enom_answer',Review::ticket($staff,$op->server_id,null,'enom_answer',$stamp),'Record Enom support answer');
        RegistrarAnswers::record($review,['operation_id'=>$op->id,'attempt_hash'=>RegistrarAnswers::identity($op),'outcome'=>'not_processed','order_reference'=>'',
            'case_reference'=>'','evidence'=>'Support checked this account and renewal order.','confirmed'=>true],$clock->now());
    }else{
        ContactRecovery::hold($op->id);
    }
}catch(Throwable $e){
    // A refusal after the locks is fine here; a deadlock or lock wait timeout is not.
    if(!MoneyDecisions::contention($e)){$refusal=get_class($e).': '.$e->getMessage();}
    else for($cause=$e;$cause!==null;$cause=$cause->getPrevious()){if($cause instanceof PDOException&&isset($cause->errorInfo[1])){$error=(int)$cause->errorInfo[1];break;}}
    if($refusal===null){$error??=0;}
}
$users=array_search('users',$locks,true);$invoices=array_search('invoices',$locks,true);
$ordered=$users!==false&&$invoices!==false&&$users<$invoices;
$complete=$error===null&&$ordered&&DB::transactionLevel()===0;
echo json_encode(['complete'=>$complete,'phase'=>$phase,'case'=>$case,'error'=>$error,'customer_first'=>$ordered,'refusal'=>$refusal,'locks'=>array_slice(array_values(array_unique($locks)),0,12),'transaction_level'=>DB::transactionLevel()])."\n";
expect($complete,'The writer waits on the checkout customer, then locks invoices, with no deadlock');$nativeSuccessful=true;
