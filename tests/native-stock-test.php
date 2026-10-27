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
// Withdrawal removes acquisition prices, and locked owned eligibility rejects a stale cart.
$cart=commerceCart($user);$item=commerceAdd($cart,$registration->fresh(),placeholderComFixtureName('unpaid-withdrawal-placeholder.com'));$before=App\Models\Order::count();
$tlds[0]->update(['register_enabled'=>false]);$native->publish($tlds[0]->id,$clock->now());same(null,$registration->fresh()->stock,'Withdrawn stock stays null');same(0,$registration->plans()->whereHas('prices')->count(),'Withdrawn acquisition projections removed');
commerceCheckout($user,$cart);same($before,App\Models\Order::count(),'Withdrawn stale cart cannot admit');
$tlds[0]->update(['register_enabled'=>true]);$native->publish($tlds[0]->id,$clock->now());same(null,$registration->fresh()->stock,'Republish stays null');expect($registration->plans()->whereHas('prices')->exists(),'Publication restores prices');
echo "Managed stock migration, finite configuration refusal, native arithmetic and withdrawal admission passed.\n";$nativeSuccessful=true;
