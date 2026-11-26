<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Two native connections, no provider calls.
// A holder takes a row the refund credit needs after its tentative credit write: real deadlocks and lock wait timeouts.
// Inversion: the holder takes an invoice, then the customer (AeStripe's order); Domains keeps customer before invoice.
declare(strict_types=1);
$deferDomainsBootstrap=true;require __DIR__.'/mariadb-bootstrap.php';
require __DIR__.'/commerce.php';require __DIR__.'/native-commerce.php';
PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());
use App\Models\{Credit,InvoiceTransaction,Role};
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Data\RenewalRequest;
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,FundsWaits,MoneyWorkSchema,NativeIntentSchema,PricingRule,Refunds,RenewalAuthority};
$root=(string)getenv('REFUND_BARRIERS');expect(is_dir($root),'Private barriers required');$role=getenv('REFUND_ROLE');
$signal=static fn(string $step)=>file_put_contents($root.'/'.$step,'ready');
$wait=static function(string $step)use($root):void {
    $end=mariadbClock()+25;while(!is_file($root.'/'.$step)){if(mariadbClock()>$end){throw new RuntimeException('Barrier timeout: '.$step);}usleep(10000);}
};
// retry: one deadlock, then the fresh retry credits. deferred: two deadlocks, queued, next sweep credits. timeout: two lock wait timeouts.
// inversion-retry and inversion-deferred: the same contracts when the holder locks invoice before customer.
// funds: two deadlocks in the funds wait closure, queued with no staff review, next sweep closes it.
// busy: a lock wait timeout in the renewal authority read is the named retryable error, with nothing changed or queued.
$cases=['retry'=>1,'deferred'=>2,'timeout'=>2,'inversion-retry'=>1,'inversion-deferred'=>2,'funds'=>2,'busy'=>1];
Queue::fake();
if($role==='holder'){
    DB::statement('SET SESSION innodb_lock_wait_timeout=10');
    foreach($cases as $case=>$rounds){
        $wait($case.'-ready');$blocker=(int)file_get_contents($root.'/blocker');$domain=(int)file_get_contents($root.'/'.$case.'-domain');
        $inversion=str_starts_with($case,'inversion-');$customer=(int)file_get_contents($root.'/'.$case.'-customer');
        if($case==='busy'){DB::beginTransaction();Domain::whereKey($domain)->lockForUpdate()->first();$signal('busy-locked-1');$wait('busy-done');DB::rollBack();continue;}
        for($round=1;$round<=$rounds;$round++){
            DB::beginTransaction();
            if($case!=='timeout'){
                // Enough undo makes InnoDB choose the refund as the deadlock victim.
                foreach(array_chunk(range(1,1000),250) as $chunk){DB::table('jobs')->insert(array_map(static fn($i)=>['queue'=>'ballast','payload'=>'{}','attempts'=>0,'available_at'=>1,'created_at'=>1],$chunk));}
            }
            if($inversion){DB::table('invoices')->where('id',(int)file_get_contents($root.'/other-invoice'))->lockForUpdate()->first();}
            else{DB::table('users')->where('id',$blocker)->lockForUpdate()->first();}
            $signal($case.'-locked-'.$round);
            if($case==='timeout'){$wait('timeout-done');DB::rollBack();break;}
            $wait($case.'-credited-'.$round);
            try{$inversion?DB::table('users')->where('id',$customer)->lockForUpdate()->first():Domain::whereKey($domain)->lockForUpdate()->first();}
            catch(QueryException $e){throw new RuntimeException('The holder was chosen as the deadlock victim: '.($e->errorInfo[1]??'unknown'));}
            // The customer lock is free only after the victim rolled back: none of its credit is committed.
            if($inversion){expect((float)DB::table('credits')->where('user_id',$customer)->sum('amount')===0.0,$case.' round '.$round.': the victim committed no credit');}
            DB::rollBack();
        }
    }
    echo json_encode(['complete'=>true,'role'=>$role])."\n";$nativeSuccessful=true;return;
}
expect($role==='victim','Known role');
require __DIR__.'/mariadb-commerce.php';
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock));
// The base revision reaches its first defect instead of a missing class.
$runner=is_file(dirname(__DIR__,2).'/Domains/Services/MoneyWorkSchema.php');NativeIntentSchema::install();if($runner){MoneyWorkSchema::install();}
DB::statement('SET SESSION innodb_lock_wait_timeout=1');
$refunds=new Refunds($clock);app()->instance(Refunds::class,$refunds);
// This database already has customers from other scenarios, some deleted.
$person=static fn():App\Models\User=>App\Models\User::create(['first_name'=>'Test','last_name'=>'Refund','email'=>'refund-'.((int)App\Models\User::max('id')+1).'@example.com','password'=>'test-password']);
$staff=$person();$staff->update(['role_id'=>Role::create(['name'=>'Contention refunds','permissions'=>['admin.domains.manage','admin.invoices.update']])->id]);
file_put_contents($root.'/blocker',(string)$person()->id);
// Another customer's invoice: outside the refund's locks, so the holder can take it before the refund customer.
file_put_contents($root.'/other-invoice',(string)DB::table('invoices')->insertGetId(['user_id'=>(int)file_get_contents($root.'/blocker'),'status'=>'pending','currency_code'=>'USD','created_at'=>now(),'updated_at'=>now()]));
$product=$native->product(Tld::where('registrar_key','enom')->sole(),'register');
$order=static function(string $name)use($product,$clock,$person):Operation {
    Carbon\Carbon::setTestNow($clock->now());$user=$person();
    foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
    $cart=commerceCart($user);commerceAdd($cart,$product,$name);commerceCheckout($user,$cart)->assertHasNoErrors();
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
    $op->update(['state'=>'held','safe_code'=>'staff_review']);return $op->fresh();
};
$credits=static fn(Operation $op):array=>DB::table('credits')->where('user_id',Domain::findOrFail($op->domain_id)->user_id)->get()->map(fn($r)=>(array)$r)->all();
$state=static fn(Operation $op):array=>['credits'=>$credits($op),'operation'=>(array)DB::table('domains_operations')->where('id',$op->id)->first(),
    'alerts'=>DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->get()->map(fn($r)=>(array)$r)->all(),
    'audit'=>DB::table(AuditArchives::TABLE)->count()];
$balance=static fn(Operation $op):int=>PricingRule::minor((string)(DB::table('credits')->where('user_id',Domain::findOrFail($op->domain_id)->user_id)->value('amount')??'0.00'));
$armed=null;$attempts=0;$snapshot=null;
// The staff decision commits first; everything after it must roll back when the credit is deferred.
Event::listen(TransactionCommitted::class,static function()use(&$armed,&$snapshot,$state):void {
    if($armed&&$snapshot===null&&DB::transactionLevel()===0){$snapshot=$state($armed['op']);}
});
$contend=static function()use(&$armed,&$attempts,$signal,$wait,$root,$credits,$balance):void {
    $attempts++;[$case,$rounds,$op]=[$armed['case'],$armed['rounds'],$armed['op']];
    same(1,DB::transactionLevel(),$case.': the credit write runs in the one outer transaction');
    // Inside the retry, nothing of the first attempt remains on the operation.
    same(null,Operation::findOrFail($op->id)->funding['refund']??null,$case.' attempt '.$attempts.': no earlier refund survives');
    $inversion=str_starts_with($case,'inversion-');
    // Each attempt writes the whole refund once: nothing of a rolled-back attempt is added to it.
    if($inversion){same(PricingRule::minor($op->amount),$balance($op),$case.' attempt '.$attempts.': the credit row holds this attempt only');}
    if($attempts>$rounds){return;}
    if($case!=='timeout'||$attempts===1){$wait($case.'-locked-'.$attempts);}
    $signal($case.'-credited-'.$attempts);
    // After its credit write the refund needs an invoice the holder took before the customer: a lock order inversion.
    if($inversion){DB::table('invoices')->where('id',(int)file_get_contents($root.'/other-invoice'))->lockForUpdate()->first();}
    else{DB::table('users')->where('id',(int)file_get_contents($root.'/blocker'))->lockForUpdate()->first();}
};
Credit::saved(static function()use(&$armed,$contend):void { if($armed&&$armed['case']!=='funds'){$contend();} });
Operation::updating(static function(Operation $row)use(&$armed,$contend):void {
    if($armed&&$armed['case']==='funds'&&$row->isDirty('state')&&$row->state==='rejected'){$contend();}
});
$orders=[];foreach($cases as $case=>$rounds){$orders[$case]=$order(placeholderComFixtureName('refund-'.$case.'-contention-placeholder.com'));}
// Holding locks route through the installed catalogue contract, which names the real adapters, not the checkout fixture subclasses.
$fixtures=[];foreach(['CartAdmission','CheckoutEligibility','Storefront','PayCheck','NativeCatalogue','StaffSettingsWrites','SellerPolicies','PriceCatalogue'] as $name){
    $type='Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$name;if(app()->bound($type)){$fixtures[$type]=app($type);app()->offsetUnset($type);}
}
$ports=new Paymenter\Extensions\Servers\Domains\Services\RuntimePorts;app()->instance(Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class,$ports);
$ports->registerInitialManagedRecordObservations(Paymenter\Extensions\Servers\Domains\Services\Runtime::initialManagedRecordObservations(...));
DB::transaction(static fn()=>Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($orders['retry']->domain));
foreach($fixtures as $type=>$fixture){app()->instance($type,$fixture);}
$proofs=[];
foreach($cases as $case=>$rounds){
    $op=$orders[$case];
    $account=Account::where('server_id',$op->server_id)->sole();
    file_put_contents($root.'/'.$case.'-domain',(string)$op->domain_id);
    file_put_contents($root.'/'.$case.'-customer',(string)Domain::findOrFail($op->domain_id)->user_id);
    if($case==='busy'){
        // The lock wait comes before any anchor check, so a held order's anchor is enough.
        $signal('busy-ready');$wait('busy-locked-1');$before=[$state($op),DB::table(MoneyWorkSchema::TABLE)->count()];$d=Domain::findOrFail($op->domain_id);
        try{app(RenewalAuthority::class)->capture(new RenewalRequest((int)$d->id,max(1,(int)$d->authority_generation),max(1,(int)$d->confirmed_revision),'2030-01-01',null));$thrown=null;}
        catch(Throwable $error){$thrown=$error;}
        $signal('busy-done');
        same(['Paymenter\\Extensions\\Servers\\Domains\\Services\\RenewalBusy',1205],[$thrown===null?null:$thrown::class,(int)($thrown?->getPrevious()?->errorInfo[1]??0)],'busy: a lock wait timeout in the authority read is the named retryable error');
        same($before,[$state($op),DB::table(MoneyWorkSchema::TABLE)->count()],'busy: nothing changed and nothing queued');
        $proofs[]=$case;continue;
    }
    if($case==='funds'){
        $op->update(['safe_code'=>'registrar_balance_insufficient','policy'=>[...$op->policy,'funds_wait_at'=>$clock->now()->modify('-4 days')->format(DATE_ATOM)]]);
        $armed=['case'=>$case,'rounds'=>$rounds,'op'=>$op];$attempts=0;$signal($case.'-ready');
        FundsWaits::sweep($account,$clock->now());$armed=null;
        $reviews=DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','funds_wait_review')->count();
        same(2,$attempts,'funds: contention is retried once, then deferred');
        same(['held',0,1],[$op->fresh()->state,$reviews,(int)DB::table(MoneyWorkSchema::TABLE)->where('subject_id',$op->id)->where('kind','funds_wait')->value('revision')],'funds: nothing closes, no staff review, the closure is queued');
        FundsWaits::sweep($account,$clock->now());
        same(['rejected',0],[$op->fresh()->state,DB::table(MoneyWorkSchema::TABLE)->where('subject_id',$op->id)->count()],'funds: the next sweep closes it and finishes the work');
        $proofs[]=$case;continue;
    }
    $armed=['case'=>$case,'rounds'=>$rounds,'op'=>$op];$attempts=0;$snapshot=null;$signal($case.'-ready');
    $result=$refunds->refundHeld($staff,$op->id,'customer_cancelled');$armed=null;
    $repairs=DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->count();
    $queued=!$runner?null:DB::table(MoneyWorkSchema::TABLE)->where('subject_id',$op->id)->where('kind','refund_rejected')->value('revision');
    if(str_ends_with($case,'retry')){
        same(2,$attempts,'retry: one deadlock is retried once');
        same(['amount'=>$op->amount,'currency'=>'USD'],$result,'retry: the fresh retry credits');
        same(PricingRule::minor($op->amount),$balance($op),'retry: the credit is exact, once');
        same([0,null],[$repairs,$queued],'retry: no repair and nothing queued');
    }else{
        same(2,$attempts,$case.': contention is retried once, then deferred');
        same(null,$result,$case.': a deferred refund reports nothing credited');
        same($snapshot,$state($op),$case.': no credit, funding, operation or audit change after the staff decision');
        same([0,1],[$repairs,(int)$queued],$case.': deferred work is queued with no repair');
        if($case==='timeout'){$signal('timeout-done');}
        $refunds->creditRetired($account);
        same(PricingRule::minor($op->amount),$balance($op),$case.': the next sweep credits exactly once');
        same(0,DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->count(),$case.': the sweep records no repair');
        same(0,DB::table(MoneyWorkSchema::TABLE)->where('subject_id',$op->id)->count(),$case.': the sweep finishes the work');
    }
    $proofs[]=$case;
}
echo json_encode(['complete'=>true,'role'=>$role,'cases'=>$proofs])."\n";$nativeSuccessful=true;
