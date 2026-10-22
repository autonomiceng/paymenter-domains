<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Two native processes, no provider calls.
declare(strict_types=1);
$deferDomainsBootstrap=true;require __DIR__.'/mariadb-bootstrap.php';
expect(!app()->bound(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class),'One scoped extension hook set per process');
require __DIR__.'/commerce.php';require __DIR__.'/native-commerce.php';
PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());

use App\Models\{User,Service};
use Illuminate\Support\Facades\{Artisan,DB,Event,Queue,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CartAdmission,CheckoutEligibility,HolderPricing,InvoiceBinding,NativeCatalogue,NativeDomainGuard,NativeIdentity,NativeUserDeletion,PriceCatalogue,PricingRules,RegistrarRegistry,SellerPolicies,UserDeletionSchema};
use Paymenter\Extensions\Servers\Domains\Tests\{FakeClock,FakeRegistrar};
$root=getenv('USERDEL_BARRIERS');expect(is_dir($root),'Private barriers required');
$phase=getenv('USERDEL_PHASE');$case=getenv('USERDEL_CASE');$prefix=(string)getenv('SCHEMA_PREFIX');
$signal=static fn(string $name)=>file_put_contents($root.'/'.$case.'-'.$name,'ready');
$wait=static function(string $name)use($root,$case):void {
    $end=microtime(true)+25;while(!is_file($root.'/'.$case.'-'.$name)){if(microtime(true)>$end){throw new RuntimeException('Barrier timeout: '.$name);}usleep(10000);}
};
if($phase==='seed'){
    Artisan::call('migrate',['--force'=>true]);
    if($prefix!==''){foreach(Schema::getTableListing(DB::connection()->getDatabaseName(),false)as$name){Schema::rename($name,$prefix.$name);}}
}
if($prefix!==''){config(['database.connections.mysql.prefix'=>$prefix]);DB::purge('mysql');Schema::clearResolvedInstance('db.schema');}
Queue::fake();
if($phase==='seed'){
    extract(commerceFixture(true));$ids=[];
    foreach(['intent','checkout','snapshot']as$name){$ids[$name]=commerceUser()->id;}
    file_put_contents($root.'/owners.json',json_encode($ids,JSON_THROW_ON_ERROR));
    echo "Seeded real native MariaDB checkout fixtures.\n";$nativeSuccessful=true;return;
}
$ids=json_decode(file_get_contents($root.'/owners.json'),true,512,JSON_THROW_ON_ERROR);$user=User::findOrFail($ids[$case]);
$clock=new FakeClock(new DateTimeImmutable('2026-10-18T12:00:00Z'));
$registry=new RegistrarRegistry;
$verifier=new class implements \Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify(\Paymenter\Extensions\Servers\Domains\Data\Environment $env,#[SensitiveParameter]array $credentials):string{return $credentials['token'];}
};
foreach(['enom','namesilo']as$key){$registry->register($key,['token'],$verifier,static function($b,$c):FakeRegistrar {
    same(0,DB::transactionLevel(),'Synthetic availability outside locks');
    return new FakeRegistrar($b,new \Paymenter\Extensions\Servers\Domains\Data\Capabilities($b,[]),
        new \Paymenter\Extensions\Servers\Domains\Data\DriverResult($b,\Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted,
            \Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,\Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,
            new \Paymenter\Extensions\Servers\Domains\Data\Evidence(premium:false,available:true)));
});}
$bindings=new AccountBindings($registry);$prices=new PriceCatalogue(new PricingRules);$native=new NativeCatalogue($prices);$sellers=new SellerPolicies($prices,$bindings);
foreach([RegistrarRegistry::class=>$registry,AccountBindings::class=>$bindings,PriceCatalogue::class=>$prices,NativeCatalogue::class=>$native,SellerPolicies::class=>$sellers,\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class=>$clock]as$class=>$instance){app()->instance($class,$instance);}
$admission=new CartAdmission($native,$sellers,new CheckoutEligibility($bindings,$clock),$clock);$admission->boot();app()->instance(CartAdmission::class,$admission);
$binder=new InvoiceBinding($admission,$native,$prices,$clock);app()->instance(InvoiceBinding::class,$binder);$binder->boot();
NativeIdentity::boot();(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
// Rebuild the same synthetic credential contract in each native process.
foreach(\Paymenter\Extensions\Servers\Domains\Models\Account::all()as$account){
    $server=\App\Models\Server::findOrFail($account->server_id);
    $credentials=json_decode($server->settings()->where('key','domains_credentials')->sole()->value,true,8,JSON_THROW_ON_ERROR);
    $clean=$registry->credentials($account->registrar_key,$credentials);
    $binding=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account);
    same(hash_hmac('sha256',$binding->fingerprint.json_encode($clean,JSON_THROW_ON_ERROR),(string)config('app.key')),$server->settings()->where('key','domains_verified_credentials')->sole()->value,'Synthetic credential contract survives native process boundary');
}
$name=$case.'-user-deletion-placeholder.com';
if($phase==='checkout'){
    $cart=commerceCart($user);commerceAdd($cart,$native->product(Tld::where('registrar_key','enom')->sole(),'register'),$name);
    $signal('ready');
    if($case==='intent'){$wait('intent');}
    if($case==='snapshot'){$wait('snapshot');}
    if($case==='checkout'){
        Event::listen(\App\Events\Order\Creating::class,static function()use($signal,$wait,$root,$case):void {
            $signal('admitted');$wait('delete-start');$end=microtime(true)+25;
            while((int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS')->n===0){
                if(microtime(true)>$end){throw new RuntimeException('Native deletion did not wait on the checkout user lock.');}usleep(10000);
            }
            file_put_contents($root.'/'.$case.'-lock-wait','proved');
        });
    }
    $page=commerceCheckout($user,$cart);
    if($case==='intent'){$page->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>NativeUserDeletion::PENDING,'type'=>'error']);same(0,Domain::where('domain',$name)->count(),'Intent-first creates no holding');}
    else{same(1,Domain::where('domain',$name)->count(),'Checkout commits its retained holding');}
    $signal('checkout-done');
}else{
    $wait('ready');
    if($case==='intent'){
        User::deleting(static function(User $u)use($signal,$wait):void{$signal('intent');$wait('checkout-done');});
        $user->delete();expect(!User::whereKey($user->id)->exists(),'Intent-first deletes safely');
        same(0,Domain::where('user_id',$user->id)->count(),'Intent-first leaves no orphan');
    }else{
        if($case==='snapshot'){DB::beginTransaction();Domain::count();$signal('snapshot');$wait('checkout-done');}
        else{$wait('admitted');$signal('delete-start');}
        $refused=false;try{$user->delete();}catch(DomainException $e){same(NativeUserDeletion::RETAINED,$e->getMessage(),'Current retained-owner refusal');$refused=true;}
        expect($refused,'Checkout-first deletion refuses');
        if($case==='snapshot'){DB::rollBack();}
        $holding=Domain::where('domain',$name)->sole();expect(User::whereKey($user->id)->exists()&&Service::whereKey($holding->service_id)->exists(),'Owner and native service retained');
        expect(!DB::table(UserDeletionSchema::TABLE)->where('user_id',$user->id)->exists(),'Refusal creates no intent');
    }
}
echo json_encode(['complete'=>true,'phase'=>$phase,'case'=>$case,'prefix'=>$prefix])."\n";$nativeSuccessful=true;
