<?php
// Registration assertions run separately; this fixture seeds only the paid domain management needs. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Cache,DB,Queue,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{NativeCatalogue,RuntimeWork};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
require __DIR__.'/enom-test-registration-fixture.php';
// Paid native orders and all invoice events remain real; this fixture does not inspect PDF layout.
Illuminate\Support\Facades\Event::listen(App\Events\Invoice\GeneratePdf::class,static function(App\Events\Invoice\GeneratePdf $event):void {
    $event->setPdfFromContent("%PDF-1.4\n% Synthetic management-fixture invoice attachment.\n%%EOF\n");
});
require_once __DIR__.'/installed-offer-evidence.php';
config(['domains.enom_writes.renewal'=>true]);
$write('test_renewal',['accept'=>true,'verified'=>true]);
saveDefaultRule(['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none']);
installedOfferEvidence($binding,$clock);
$tld=Tld::where('server_id',$server->id)->sole();
$write('tld_settings',['enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true],$tld->id);
$write('publish',[],$tld->id);
$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$tld->id);
Schema::create('cache',static function($table):void {$table->string('key')->primary();$table->mediumText('value');$table->integer('expiration')->index();});
Schema::create('cache_locks',static function($table):void {$table->string('key')->primary();$table->string('owner');$table->integer('expiration');});
config(['cache.default'=>'database','session.driver'=>'database','queue.default'=>'database','queue.connections.database.retry_after'=>900]);
Queue::fake();
$user=commerceUser();$user->update(['email'=>'registration-owner@example.com']);
foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
$write('processing',['enabled'=>true,'confirmed'=>true]);$write('publish',[],$tld->id);
$product=app(NativeCatalogue::class)->product($tld,'register');
// The native account contact is complete; submit the credit choice and checkout in one request.
same(5,$user->properties()->whereIn('key',['address','city','zip','country','phone'])->distinct()->count('key'),'Management checkout uses the complete native account contact');
$managementCheckout=static function(App\Models\Cart $cart)use($user){
    Illuminate\Support\Once::flush();
    return Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class)
        ->update(calls:[['method'=>'checkout','params'=>[],'path'=>'']],updates:['use_credits'=>false]);
};
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('paid-test-placeholder.com'));
$managementCheckout($cart)->assertHasNoErrors();unset($cart);gc_collect_cycles();gc_mem_caches();
$op=Domain::where('domain','paid-test-placeholder.com')->sole()->operations()->sole();
App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount);
$worker=app(RuntimeWork::class);Cache::flush();$worker->operation($op->id);
$GLOBALS['testRegisteredName']=$op->domain->domain;
$offlinePsl=static fn():never=>throw new Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed');
app()->instance(Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,new Paymenter\Extensions\Servers\Domains\Services\PslTransport($offlinePsl));
Cache::flush();$worker->operation($op->id);unset($GLOBALS['testRegisteredName']);
$d=$op->domain->fresh();
same('active',$d->status,'Management fixture registers and reconciles one paid TEST domain');
$recoveryCart=commerceCart($user);commerceAdd($recoveryCart,$product,placeholderComFixtureName('sealed-recovery-placeholder.com'));
$managementCheckout($recoveryCart)->assertHasNoErrors();unset($recoveryCart);gc_collect_cycles();gc_mem_caches();
$recoveryOp=Domain::where('domain','sealed-recovery-placeholder.com')->sole()->operations()->sole();
App\Helpers\ExtensionHelper::addPayment($recoveryOp->invoice_id,null,$recoveryOp->amount);
resetWire(['reply'=>$reply,'during'=>static function(array $p):void {if($p['Command']==='Purchase'){$GLOBALS['enomWire']['timeout']=true;}}]);
$worker->operation($recoveryOp->id);
same('uncertain',$recoveryOp->fresh()->state,'Recovery fixture retains an uncertain TEST registration');
same('sealed',$recoveryOp->fresh()->attempt['seal']??null,'Recovery fixture retains its sent attempt seal');
resetWire(['reply'=>$reply]);
file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));
