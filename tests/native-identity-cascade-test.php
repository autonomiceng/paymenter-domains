<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());

use App\Models\{User,Service};
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission,CheckoutEligibility,InvoiceBinding,NativeIdentity,NativeDomainGuard,HolderPricing,ReminderSchema,NativeIdentityDiscrepancies,RuntimeHealth};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
Queue::fake();extract(commerceFixture(true));$clock=new FakeClock($now);
ReminderSchema::install();NativeIdentity::boot();
$admission=new CartAdmission($native,$sellers,new CheckoutEligibility($bindings,$clock),$clock);$admission->boot();
$binder=new InvoiceBinding($admission,$native,$prices,$clock);app()->instance(InvoiceBinding::class,$binder);$binder->boot();
(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
$user=commerceUser();$cart=commerceCart($user);$product=$native->product($tlds[0],'register');
commerceAdd($cart,$product,placeholderComFixtureName('parent-race-placeholder.com'));
// Inspect the real admitted line before native checkout creates its canonical Property.
$admittedPropertyCases=0;
Service::created(static function(Service $s)use($binder,&$admittedPropertyCases):void {
    if($admittedPropertyCases!==0){return;}
    $make=static fn(array $changes=[])=> (new App\Models\Property)->forceFill(array_replace([
        'model_type'=>$s->getMorphClass(),'model_id'=>$s->id,'key'=>'domain','name'=>'Domain','value'=>'parent-race-placeholder.com'
    ],$changes));
    expect($binder->identityProperty($make()),'Exact admitted property is allowed');
    $reject=static function(array $changes,string $label)use($make,&$admittedPropertyCases):void {
        $refused=false;$before=App\Models\Property::count();
        try{$make($changes)->save();}catch(DomainException $e){
            same('This change affects managed domain identity and was refused.',$e->getMessage(),$label);$refused=true;
        }
        expect($refused,$label.' refused during admitted checkout');
        same($before,App\Models\Property::count(),$label.' did not persist');$admittedPropertyCases++;
    };
    $reject(['value'=>'wrong-admitted-placeholder.com'],'Wrong admitted name');
    $reject(['custom_property_id'=>987654],'Custom definition on admitted property');
    $reject(['CUSTOM_PROPERTY_ID'=>987654],'Alternate custom definition on admitted property');
    // Fixture-only temporary row isolates the duplicate condition before normal checkout continues.
    $id=DB::table('properties')->insertGetId($make()->getAttributes());
    try{$reject([],'Duplicate admitted property');}finally{DB::table('properties')->where('id',$id)->delete();}
});

// Last installed deleting listener: the deletion guard has already returned.
User::deleting(static function(User $u)use($user):void {if($u->id===$user->id){Fiber::suspend('after-all-guards-before-delete');}});
$delete=new Fiber(fn()=>$user->fresh()->delete());
same('after-all-guards-before-delete',$delete->start(),'Paused after the deletion guard');
same(0,DB::transactionLevel(),'Paused native delete has no outer transaction');
commerceCheckout($user,$cart)->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>\Paymenter\Extensions\Servers\Domains\Services\NativeUserDeletion::PENDING,'type'=>'error']);
same(0,Domain::count(),'Deletion-first checkout must not create a retained domain');
$delete->resume();expect($delete->isTerminated(),'Native delete completed');
expect(!User::whereKey($user->id)->exists(),'Deletion-first user deleted safely');
// A healthy owner still checks out. Raw maintenance creates historical orphans only for diagnostics.
$user=commerceUser();$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('parent-race-placeholder.com'));
commerceCheckout($user,$cart);
same(4,$admittedPropertyCases,'Real checkout exercised every admission refusal');
$holding=Domain::where('domain','parent-race-placeholder.com')->sole();$serviceId=$holding->service_id;
expect(Service::whereKey($serviceId)->exists(),'Healthy checkout committed native service');
// Synthetic additional orphan candidates verify the deleted callback's bounded work.
for($n=0;$n<25;$n++){$overflow=Domain::create(['user_id'=>$user->id,'service_id'=>987654+$n,'domain'=>placeholderComFixtureName('overflow-'.$n.'-placeholder.com'),'sld'=>'overflow-'.$n.'-placeholder','tld'=>'com',
    'server_id'=>$tlds[0]->server_id,'registrar_key'=>'enom','account_fingerprint'=>$tlds[0]->account_fingerprint,'status'=>'registration_pending']);}
DB::table('users')->where('id',$user->id)->delete();
Illuminate\Support\Facades\Event::dispatch('eloquent.deleted: '.User::class,[$user]);
expect(!User::whereKey($user->id)->exists(),'Privileged maintenance deleted diagnostic owner');
expect(!Service::whereKey($serviceId)->exists(),'Native FK cascade deleted routed service');
expect(Domain::whereKey($holding->id)->exists(),'Retained ledger is orphaned');
$alerts=fn()=>DB::table('domains_obligation_alerts')->where('domain_id',$holding->id)->where('code','native_identity_missing');
same(1,$alerts()->whereNull('resolved_at')->count(),'Post-delete observer records the orphan');
same(25,DB::table('domains_obligation_alerts')->where('code','native_identity_missing')->count(),'Deleted callback caps immediate diagnostic rows');
expect(!DB::table('domains_obligation_alerts')->where('domain_id',$overflow->id)->exists(),'Overflow waits for durable cursor');
$firstSeen=$alerts()->value('first_seen');NativeIdentityDiscrepancies::inspect($holding,now()->getTimestamp()+60);
same(1,$alerts()->count(),'Durable repeat inspection is idempotent');same($firstSeen,$alerts()->value('first_seen'),'First sight retained');
// This is diagnostic only; an unrelated native User deletion still succeeds without a transaction.
$unrelated=commerceUser();$unrelated->delete();expect(!User::whereKey($unrelated->id)->exists(),'Native deletion remains available');
$newOwner=commerceUser();$replacementCart=commerceCart($newOwner);
$before=[App\Models\Order::count(),Service::count(),Domain::count(),App\Models\Invoice::count(),Paymenter\Extensions\Servers\Domains\Models\Operation::count()];
try{commerceAdd($replacementCart,$product,placeholderComFixtureName('parent-race-placeholder.com'));commerceCheckout($newOwner,$replacementCart);}catch(DomainException){}
same($before,[App\Models\Order::count(),Service::count(),Domain::count(),App\Models\Invoice::count(),Paymenter\Extensions\Servers\Domains\Models\Operation::count()],'Retained reservation forbids new checkout readmission');
// The same replacement owner can still check out an unrelated fresh name.
$freshCart=commerceCart($newOwner);commerceAdd($freshCart,$product,placeholderComFixtureName('fresh-after-orphan-placeholder.com'));commerceCheckout($newOwner,$freshCart);
$fresh=Domain::where('domain','fresh-after-orphan-placeholder.com')->sole();
same((int)$newOwner->id,(int)$fresh->user_id,'Healthy replacement-owner checkout succeeds');
expect(Service::whereKey($fresh->service_id)->exists(),'Healthy fresh name retains its native service');
echo "Deletion-first native checkout refuses; historical maintenance orphans retain durable staff discrepancies.\n";
$nativeSuccessful=true;
