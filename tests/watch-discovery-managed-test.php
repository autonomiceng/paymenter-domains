<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-discovery.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,WatchName,WatchSource};
$f=discoveryFixture();extract($f);$sweep();same(1,WatchName::count(),'Runtime discovers the external source before acquisition');$w=WatchName::sole();
// A synthetic ledger holding is deliberately missing financial proof: discovery must never fabricate it.
$registrar=App\Models\Server::create(['name'=>'Synthetic registrar','extension'=>'Domains','type'=>'server','enabled'=>true]);
$account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$registrar->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic-discovery-binding']));
$managedOrder=App\Models\Order::create(['user_id'=>$other->id,'currency_code'=>'USD']);
$managed=App\Models\Service::create(['user_id'=>$other->id,'order_id'=>$managedOrder->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
$domain=Domain::create(['server_id'=>$registrar->id,'registrar_key'=>'enom','account_fingerprint'=>$account->account_fingerprint,'domain'=>'example.com','sld'=>'example','tld'=>'com','user_id'=>$other->id,'service_id'=>$managed->id,'status'=>'active']);
$sweep();same('watch',WatchSource::where('service_id',$service->id)->value('state'),'Foreign reservation has the same external result as no match');same(null,$w->fresh()->managed_domain_id,'Foreign domain ID is never attached');
DB::table('domains_domains')->where('id',$domain->id)->update(['user_id'=>$owner->id]);$sweep();same('watch',WatchSource::where('service_id',$service->id)->value('state'),'Ledger owner alone cannot make a managed match');
DB::table('services')->where('id',$managed->id)->update(['user_id'=>$owner->id]);DB::table('orders')->where('id',$managedOrder->id)->update(['user_id'=>$owner->id]);
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'adoption_pending','provenance'=>'adoption_pending']);$sweep();same('watch',WatchSource::where('service_id',$service->id)->value('state'),'Unproven adopted holding remains an external watch');
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'registration_pending','provenance'=>null]);$sweep();
same('managed_link',WatchSource::where('service_id',$service->id)->value('state'),'Pending acquisition becomes a read-only managed source');same('linked',$w->fresh()->state,'Pending acquisition stops external work');same(null,$w->fresh()->registration,'Pending acquisition does not prove platform registration');
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'active']);$sweep();same('platform',$w->fresh()->registration,'Active same-owner holding is linked');same(0,WatchName::where('state','active')->count(),'Managed holdings have no duplicate active watch');
same(null,$domain->fresh()->snapshot,'Discovery never fabricates holding or checkout authority');same(0,DB::table('domains_operations')->count(),'Discovery creates no financial operation');
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'registration_rejected']);$sweep();same('watch',WatchSource::where('service_id',$service->id)->value('state'),'Failed acquisition restores external monitoring');same('active',$w->fresh()->state,'Linked tombstone can return to monitoring');same(null,$w->fresh()->registration,'Old platform classification is cleared');
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'active']);$new=discoveryService($f,'new.example.com');$sweep();same('managed_link',WatchSource::where('service_id',$new->id)->value('state'),'Already managed name links new hosting without creating a watch');same(1,WatchName::where('canonical_name','example.com')->count(),'Managed and external deduplication is owner/name scoped');same([],$dns->calls,'Managed linking is local only');
echo "Foreign holdings, native owner checks, pending adoption, acquisition links and external return passed.\n";$nativeSuccessful=true;
