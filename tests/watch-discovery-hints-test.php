<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';require __DIR__.'/Support/registration-refusal-reply.php';require __DIR__.'/Support/watch-discovery.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchSource;
use Paymenter\Extensions\Servers\Domains\Services\DomainReservations;
$makeEngine($bindingFor(0),static fn()=>$purchaseRefusal);$refused=$runRegistration($new('release-placeholder.com'));(new Paymenter\Extensions\Servers\Domains\Services\Refunds($clock))->creditRejected($refused->id);$financialOwner=$user;
$emptyCart=commerceCart($user);app('request')->cookies->set('cart',$emptyCart->ulid);Illuminate\Support\Once::flush();
app()->offsetUnset(Paymenter\Extensions\Servers\Domains\Services\Runtime::class);app()->offsetUnset(Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class);
$f=discoveryFixture();extract($f);
DB::table('services')->where('id',$service->id)->update(['user_id'=>$financialOwner->id]);DB::table('orders')->where('id',$order->id)->update(['user_id'=>$financialOwner->id]);$owner=$financialOwner;$service=$service->fresh();
$property->update(['value'=>'release-placeholder.com']);$sweep();
$target=DB::table('domains_watch_discovery')->where('product_id',$product->id);$target->update(['hint'=>false,'due_at'=>$clock->at+86400]);
$before=$refused->fresh()->only(['attempt','funding','cost','state']);
DomainReservations::releaseTerminal('release-placeholder.com');
same(null,$refused->domain->fresh()->reservation_name,'Real terminal release clears the reservation under its existing guards');
expect((bool)(clone$target)->value('hint'),'Query-builder terminal release hints the formerly linked hosting name after commit');
same($before,$refused->fresh()->only(['attempt','funding','cost','state']),'Discovery hints never rewrite financial evidence');
$work->tick($clock->at);same('watch',WatchSource::where('service_id',$service->id)->value('state'),'Released refusal remains an external monitoring source');
// A same-owner held source on a separate product has no name, so a cleared reservation never hints it.
$heldProduct=(int)DB::table('domains_watch_product_maps')->max('product_id')+1;
$heldMap=DB::table('domains_watch_product_maps')->insertGetId(['product_id'=>$heldProduct,'property_key'=>'domain','edited_by'=>$service->user_id,'reason'=>'Synthetic held map']);
DB::table('domains_watch_sources')->insert(['user_id'=>$owner->id,'service_id'=>899999,'map_id'=>$heldMap,'map_revision'=>1,'hostname'=>null,'registrable_name'=>null,'state'=>'held','safe_code'=>'source_invalid','source_digest'=>hash('sha256','held-separate'),'last_seen_at'=>$clock->at]);
DB::table('domains_watch_discovery')->insert(['product_id'=>$heldProduct,'hint'=>false,'due_at'=>$clock->at+86400]);
$queries=[];DB::listen(static function($q)use(&$queries):void{if(str_contains($q->sql,'domains_watch_sources')){$queries[]=$q->sql;}});
$refused->domain->refresh()->update(['confirmed_revision'=>2]);
expect(!array_filter($queries,fn($q)=>str_contains($q,'"registrable_name" is null')),'Cleared reservations never look up unrelated null-name held sources');
same(false,(bool)DB::table('domains_watch_discovery')->where('product_id',$heldProduct)->value('hint'),'The separate held product keeps its cleared hint after the domain update');
$rows=DB::table('domains_watch_discovery')->count();$work->hint(0);same($rows,DB::table('domains_watch_discovery')->count(),'Nonpositive hints remain pre-SQL no-ops');
// Hosting products past the first page of one hundred are still hinted for the same owner and name.
$base=(int)DB::table('domains_watch_product_maps')->max('product_id')+1;$at=$clock->at;
foreach(range(0,100)as$i){
    $map=DB::table('domains_watch_product_maps')->insertGetId(['product_id'=>$base+$i,'property_key'=>'domain','edited_by'=>$service->user_id,'reason'=>'Synthetic page map']);
    DB::table('domains_watch_sources')->insert(['user_id'=>$owner->id,'service_id'=>900000+$i,'map_id'=>$map,'map_revision'=>1,'hostname'=>'release-placeholder.com','registrable_name'=>'release-placeholder.com','state'=>'held','safe_code'=>'synthetic','source_digest'=>hash('sha256','page'.$i),'last_seen_at'=>$at]);
}
DB::table('domains_watch_discovery')->update(['hint'=>false]);
Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryHints::names([[(int)$owner->id,'release-placeholder.com']]);
same(102,DB::table('domains_watch_discovery')->where('hint',true)->count(),'Every mapped product for the name is hinted across pages');
expect((bool)DB::table('domains_watch_discovery')->where('product_id',$base+100)->value('hint'),'The product after the first hundred is hinted');
same([],$dns->calls,'Hints and release are local only');echo "Native terminal-release discovery hints passed.\n";$nativeSuccessful=true;
