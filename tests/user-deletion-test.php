<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());

use App\Models\{Invoice,Order,Service,User};
use Illuminate\Support\Facades\{DB,Event,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingIdentityLock,HolderPricing,NativeDomainGuard,NativeIdentity,NativeUserDeletion,UserDeletionSchema};
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);
(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();NativeIdentity::boot();
$op=$new(placeholderComFixtureName('delete-retained-placeholder.com'));
$reject=static function(callable $call,string $message):void {
    $refused=false;try{$call();}catch(DomainException $e){same($message,$e->getMessage(),'Exact deletion refusal');$refused=true;}
    expect($refused,'Deletion must refuse');
};
$rows=static fn()=>[User::count(),Order::count(),Service::count(),Domain::count(),Operation::count(),DB::table(UserDeletionSchema::TABLE)->count()];
// Retained ownership does not depend on status, reservation, funding or native children.
foreach(['registration_pending','active','cancelled'] as$status){
    DB::table('domains_domains')->where('id',$op->domain_id)->update(['status'=>$status,'reservation_name'=>null]);
    $before=$rows();for($i=0;$i<2;$i++){$reject(fn()=>$user->fresh()->delete(),NativeUserDeletion::RETAINED);}
    same($before,$rows(),'Refused deletion changes nothing across repeated states');
}
DB::table('domains_domains')->where('id',$op->domain_id)->update(['status'=>'registration_pending','reservation_name'=>$op->domain->domain]);
// Original ID cannot be hidden by changing the in-memory key.
$spoof=$user->fresh();$spoof->id=987654;$reject(fn()=>$spoof->delete(),NativeUserDeletion::RETAINED);
// Isolate a retained domain without any routed native service or operation for its owner.
$direct=commerceUser();DB::table('domains_domains')->where('id',$op->domain_id)->update(['user_id'=>$direct->id]);
$reject(fn()=>$direct->delete(),NativeUserDeletion::RETAINED);
DB::table('domains_domains')->where('id',$op->domain_id)->update(['user_id'=>$user->id]);
// Isolate both routed ownership paths before a first holding exists.
$hosting=commerceHosting();$makeService=static function(User $owner,User $orderOwner,bool $routed)use($product,$hosting):Service {
    $order=Order::findOrFail(DB::table('orders')->insertGetId(['user_id'=>$orderOwner->id,'currency_code'=>'USD','created_at'=>now(),'updated_at'=>now()]));$p=$routed?$product:$hosting;
    $id=DB::table('services')->insertGetId(['user_id'=>$owner->id,'order_id'=>$order->id,'product_id'=>$p->id,
        'plan_id'=>$p->plans()->first()->id,'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
    return Service::findOrFail($id);
};
foreach([false,true] as$indirect){
    $owner=commerceUser();$orderOwner=commerceUser();
    $s=$makeService($owner,$orderOwner,true);$target=$indirect?$orderOwner:$owner;
    $reject(fn()=>$target->fresh()->delete(),NativeUserDeletion::RETAINED);
    expect(!DB::table(UserDeletionSchema::TABLE)->where('user_id',$target->id)->exists(),'Route refusal leaves no intent');
}
// A holding routed through a native service is retained even after privileged owner mismatch.
$serviceOwner=commerceUser();$foreign=commerceUser();$s=$makeService($serviceOwner,$foreign,false);
$raw=$op->domain->getAttributes();unset($raw['id']);$raw['user_id']=$foreign->id;$raw['service_id']=$s->id;
$raw['domain']='service-route-placeholder.com';$raw['sld']='service-route-placeholder';$raw['reservation_name']=null;
DB::table('domains_domains')->insert($raw);$reject(fn()=>$serviceOwner->fresh()->delete(),NativeUserDeletion::RETAINED);
// Isolate service-only and invoice-only obligations without a matching domain or routed service.
foreach(['service','invoice']as$route){
    $obligated=commerceUser();$foreign=commerceUser();
    $s=$makeService($route==='service'?$obligated:$foreign,$foreign,false);
    $invoice=Invoice::create(['user_id'=>($route==='invoice'?$obligated:$foreign)->id,'currency_code'=>'USD','status'=>'pending','due_at'=>now()->addDay()]);
    $raw=$op->getAttributes();unset($raw['id']);$raw['domain_id']=987654;$raw['service_id']=$s->id;$raw['invoice_id']=$invoice->id;$raw['invoice_item_id']=null;$raw['operation_key']='deleted-domain-obligation-'.$route;
    DB::table('domains_operations')->insert($raw);
    $reject(fn()=>$obligated->fresh()->delete(),NativeUserDeletion::RETAINED);
}
// A later deleting listener fails after the intent commits. Checkout and adoption stay held.
$failed=commerceUser();$cart=commerceCart($failed);commerceAdd($cart,$product,placeholderComFixtureName('failed-delete-placeholder.com'));
$failure=true;User::deleting(static function(User $u)use($failed,&$failure):void {if($u->id===$failed->id&&$failure){throw new RuntimeException('Synthetic later deletion failure');}});
try{$failed->fresh()->delete();expect(false,'Later listener must fail');}catch(RuntimeException $e){same('Synthetic later deletion failure',$e->getMessage(),'Real late event failed');}
expect(User::whereKey($failed->id)->exists(),'Failed native delete retains user');
$intent=DB::table(UserDeletionSchema::TABLE)->where('user_id',$failed->id)->sole();$before=$rows();
for($i=0;$i<2;$i++){commerceCheckout($failed,$cart)->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>NativeUserDeletion::PENDING,'type'=>'error']);same($before,$rows(),'Pending deletion refuses repeated checkout without writes');}
// A marked customer can still use ordinary hosting checkout.
$hostingCart=commerceCart($failed);$hostingCart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);
$hostingBefore=Service::where('user_id',$failed->id)->count();commerceCheckout($failed,$hostingCart);
same($hostingBefore+1,Service::where('user_id',$failed->id)->count(),'Deletion intent blocks only domain acquisition');
// Defensive adoption check runs at its user prefix, before any service lookup.
$reject(fn()=>DB::transaction(fn()=>HoldingIdentityLock::lockForAdoption(987654,987654)), 'Missing owner.');
$existing=$makeService($failed,$failed,false);
$reject(fn()=>DB::transaction(fn()=>HoldingIdentityLock::lockForAdoption($existing->order_id,$existing->id)),NativeUserDeletion::PENDING);
$failure=false;$failed->fresh()->delete();
expect(!User::whereKey($failed->id)->exists(),'Retry completes deletion');
same((array)$intent,(array)DB::table(UserDeletionSchema::TABLE)->where('user_id',$failed->id)->sole(),'Completed deletion retains intent without restamping');
// A second paused deletion can still delete a manually reused ID after the first completes.
$twice=commerceUser();$attrs=$twice->getAttributes();
User::deleting(static function(User $u)use($twice):void {if($u->id===$twice->id){Fiber::suspend('delete-ready');}});
$first=new Fiber(fn()=>$twice->fresh()->delete());$second=new Fiber(fn()=>$twice->fresh()->delete());
same('delete-ready',$first->start(),'First concurrent deletion passed guard');same('delete-ready',$second->start(),'Second concurrent deletion passed guard');
$first->resume();expect($first->isTerminated(),'First concurrent deletion completed');
DB::table('users')->insert($attrs);$replacement=User::findOrFail($twice->id);$cart=commerceCart($replacement);
commerceAdd($cart,$product,placeholderComFixtureName('reused-id-placeholder.com'));
commerceCheckout($replacement,$cart)->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>NativeUserDeletion::PENDING,'type'=>'error']);
expect(!Domain::where('user_id',$replacement->id)->exists(),'Reused ID never receives a new holding');
$second->resume();expect($second->isTerminated()&&!User::whereKey($twice->id)->exists(),'Paused second delete still reaches reused ID safely');
// Native outer rollback removes both deletion and intent. A later cycle remains valid.
$rollback=commerceUser();
for($i=0;$i<2;$i++){
    DB::beginTransaction();$rollback->fresh()->delete();expect(!User::whereKey($rollback->id)->exists(),'Deleted inside outer transaction');DB::rollBack();
    expect(User::whereKey($rollback->id)->exists(),'Rollback restores user');expect(!DB::table(UserDeletionSchema::TABLE)->where('user_id',$rollback->id)->exists(),'Rollback removes deletion intent');
}
$cart=commerceCart($rollback);commerceAdd($cart,$product,placeholderComFixtureName('rollback-checkout-placeholder.com'));commerceCheckout($rollback,$cart);
expect(Domain::where('user_id',$rollback->id)->exists(),'Rollback allows later checkout');
// Inspect requested current reads: SQLite itself omits FOR UPDATE.
$traceOwner=commerceUser();$connection=DB::connection();$original=$connection->getQueryGrammar();
$trace=new class($connection) extends Illuminate\Database\Query\Grammars\SQLiteGrammar {
    public array $reads=[];
    public function compileSelect(Illuminate\Database\Query\Builder $query){
        if($query->lock){$this->reads[]=['table'=>$query->from,'orders'=>$query->orders];}
        return parent::compileSelect($query);
    }
};
$connection->setQueryGrammar($trace);
try{$traceOwner->delete();}finally{$connection->setQueryGrammar($original);}
$tables=array_column($trace->reads,'table');
$prefix=['users','invoices','orders','services','domains_domains','domains_operations',UserDeletionSchema::TABLE];
foreach($prefix as$index=>$table){
    $position=array_search($table,$tables,true);expect($position!==false,'Deletion requires current read: '.$table);
    if($index>0){expect(array_search($prefix[$index-1],$tables,true)<$position,'Deletion lock order: '.$table);}
    if($table!==UserDeletionSchema::TABLE){same([['column'=>'id','direction'=>'asc']],$trace->reads[$position]['orders'],'Sorted deletion rows: '.$table);}
}
$trace->reads=[];$connection->setQueryGrammar($trace);
try{DB::transaction(static function()use($user):void {User::whereKey($user->id)->lockForUpdate()->firstOrFail();NativeUserDeletion::requireOwner($user->id);});}
finally{$connection->setQueryGrammar($original);}
same(['users',UserDeletionSchema::TABLE],array_column($trace->reads,'table'),'Admission current-reads intent under its native user lock');
// Nested native writers fail at the lock seam, before taking any rows.
$nested=commerceUser();DB::beginTransaction();DB::beginTransaction();
try{$reject(fn()=>$nested->delete(),'Customer deletion requires the outer database transaction.');}finally{DB::rollBack();DB::rollBack();}
// Missing upgrade storage fails closed. Renaming restores the exact retained table afterward.
$unrelated=commerceUser();Schema::rename(UserDeletionSchema::TABLE,'domains_saved_user_deletions');
try{
    $reject(fn()=>$unrelated->delete(),'Customer deletion is unavailable until the Domains update is finished.');
    $reject(fn()=>DB::transaction(fn()=>NativeUserDeletion::requireOwner($unrelated->id)),'Domain checkout is unavailable until the Domains update is finished.');
}finally{Schema::rename('domains_saved_user_deletions',UserDeletionSchema::TABLE);}
// The native staff action shows one handled refusal and leaves the native/retained rows unchanged.
staffPanel();$staff=staffUser(['admin.users.viewAny','admin.users.view','admin.users.update','admin.users.delete']);
$before=$rows();session()->forget('filament.notifications');
$page=Livewire\Livewire::actingAs($staff)->test(App\Admin\Resources\UserResource\Pages\EditUser::class,['record'=>$user->id]);
$page->callAction('delete')->assertSuccessful()->assertActionNotMounted('delete')
    ->assertNotified(Filament\Notifications\Notification::make()->danger()->title('Customer could not be deleted')->body(NativeUserDeletion::RETAINED));
same($before,$rows(),'Handled staff refusal changes nothing');
// Native unrelated users still delete without an outer transaction.
$unrelated->fresh()->delete();expect(!User::whereKey($unrelated->id)->exists(),'Unrelated customer deletion works');
echo "User deletion intent, retained routes and obligations, retries, rollback, missing schema and staff refusal passed. SQLite interleaving only; no MariaDB lock-wait proof.\n";
$nativeSuccessful=true;
