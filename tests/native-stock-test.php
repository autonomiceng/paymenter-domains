<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{NativeIdentity,NativeStock};
$hosting=commerceHosting();$registration=$product;$transfer=$native->product($tlds[0],'transfer');
// Simulate historical projections before migration, including zero and positive stock.
DB::table('products')->where('id',$registration->id)->update(['stock'=>0]);DB::table('products')->where('id',$transfer->id)->update(['stock'=>9]);
$migration=require dirname(__DIR__).'/Domains/database/migrations/2026_10_19_000016_null_managed_stock.php';$migration->up();$migration->up();
same(null,$registration->fresh()->stock,'Old zero becomes null');same(null,$transfer->fresh()->stock,'Old positive becomes null');same(10,$hosting->fresh()->stock,'Migration leaves hosting stock');
NativeStock::boot();
foreach([0,1,9]as$finite){$refused=false;try{$registration->fresh()->update(['stock'=>$finite]);}catch(Illuminate\Validation\ValidationException $e){$refused=true;expect(str_contains($e->getMessage(),'unlimited stock'),'Staff finite stock message');}expect($refused,'Finite configuration refused');}
$refused=false;try{Product::create(['name'=>'Finite domain','slug'=>'finite-domain','category_id'=>$registration->category_id,'server_id'=>$registration->server_id,'hidden'=>true,'stock'=>0]);}catch(Illuminate\Validation\ValidationException){$refused=true;}expect($refused,'New routed finite product refused');
$p=$registration->fresh();$p->increment('stock',1);same(null,$p->fresh()->stock,'Native increment on NULL does not throw or replenish');$p->decrement('stock',1);same(null,$p->fresh()->stock,'NULL decrement remains null');
$hosting->update(['stock'=>4]);$hosting->increment('stock',1);same(5,$hosting->fresh()->stock,'Hosting remains native');
// Withdrawal closes offer admission while retaining native price IDs/amounts used by existing services.
$cart=commerceCart($user);$item=commerceAdd($cart,$registration->fresh(),placeholderComFixtureName('unpaid-withdrawal-placeholder.com'));$before=App\Models\Order::count();
$projection=static fn()=>App\Models\Price::whereIn('plan_id',$registration->plans()->pluck('id'))->orderBy('id')->get()->map(fn($p)=>$p->getRawOriginal())->all();$retainedPrices=$projection();
$stockBinding=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole());
$offer=static fn(Paymenter\Extensions\Servers\Domains\Catalogue\Context $context=Paymenter\Extensions\Servers\Domains\Catalogue\Context::Public)=>activeFixturePorts($native)->readOffers(new Paymenter\Extensions\Servers\Domains\Catalogue\Query($stockBinding,['com'],'register'),$context)[0];
activeFixtureStops($native,$tlds[0],['register']);activeFixtureRepublish($native,$tlds[0]->id,$clock->now());same(null,$registration->fresh()->stock,'Withdrawn stock stays null');
same([false,'unavailable'],[$offer()->available,$offer()->reason],'Withdrawal closes public acquisition admission');same([false,'staff_stopped'],[$offer(Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)->available,$offer(Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)->reason],'Staff retain the exact withdrawal reason');same($retainedPrices,$projection(),'Withdrawal retains every native price ID and amount');
commerceCheckout($user,$cart);same($before,App\Models\Order::count(),'Withdrawn stale cart cannot admit');
activeFixtureStops($native,$tlds[0],[]);activeFixtureRepublish($native,$tlds[0]->id,$clock->now());same(null,$registration->fresh()->stock,'Republish stays null');expect($registration->plans()->whereHas('prices')->exists(),'Publication retains native prices');same(true,$offer()->available,'Publication restores acquisition admission');
echo "Managed stock migration, finite configuration refusal, native arithmetic and withdrawal admission passed.\n";$nativeSuccessful=true;
