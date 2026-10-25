<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php'; require __DIR__.'/Support/staff-panel.php';
use App\Models\{Category,Currency,Product,Plan,Server,Service,Order,User};
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,WatchProductMap,WatchName,WatchSource,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{WatchSchema,WatchMappings};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$entry=new Paymenter\Extensions\Servers\Domains\Domains([]);$entry->installed();WatchSchema::install();WatchSchema::install();
function watchRefused(Closure $action, string $label): void
{
    $refused=false;try{DB::transaction($action);}catch(DomainException|InvalidArgumentException|Illuminate\Database\QueryException $e){$refused=true;}expect($refused,$label);
}
$staff=staffUser([Staff::VIEW,'admin.domains.watch.view','admin.domains.watch.manage']);$owner=staffUser([]);$other=staffUser([]);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$category=Category::create(['name'=>'Synthetic hosting','slug'=>'synthetic-hosting']);
$server=Server::create(['name'=>'Synthetic hosting','extension'=>'SyntheticHosting','type'=>'server','enabled'=>true]);
$product=Product::create(['name'=>'Hosting','slug'=>'synthetic-hosting','category_id'=>$category->id,'server_id'=>$server->id,'hidden'=>true,'allow_quantity'=>'combined']);
$plan=$product->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
$order=Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
$service=Service::create(['user_id'=>$owner->id,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
$writer=new WatchMappings;$denied=false;try{$writer->save($owner,$product->id,'domain',0,'Unauthorized');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$denied=$e->getStatusCode()===403;}expect($denied,'Watch permissions default deny');
$map=$writer->save($staff,$product->id,'primary_domain',0,'Map primary hostname');
$mapRaw=DB::table('domains_watch_product_maps')->where('id',$map->id)->value('reason');
expect(str_starts_with($mapRaw,'encrypted:v1:'),'New mapping ciphertext has a version');
expect($mapRaw!=='Map primary hostname'&&!str_contains($mapRaw,'Map primary hostname'),'Mapping reason is encrypted at rest');
same('Map primary hostname',Illuminate\Support\Facades\Crypt::decryptString(substr($mapRaw,strlen('encrypted:v1:'))),'Mapping reason uses application encryption');
expect(!array_key_exists('reason',$map->toArray()),'Mapping reason hidden from serialization');
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['reason'=>'Legacy mapping reason']);
same('Legacy mapping reason',$map->fresh()->reason,'Legacy mapping remains readable');
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['reason'=>$mapRaw]);
$audit=WatchAudit::sole();same('Map primary hostname',$audit->reason,'Audit reason round trips');
$raw=DB::table('domains_watch_audit')->where('id',$audit->id)->value('reason');
expect($raw!=='Map primary hostname'&&!str_contains($raw,'Map primary hostname'),'Audit reason is encrypted at rest');
same('Map primary hostname',Illuminate\Support\Facades\Crypt::decryptString(substr($raw,strlen('encrypted:v1:'))),'Reason uses application encryption');
expect(!array_key_exists('reason',$audit->toArray()),'Reason hidden from model serialization');
DB::table('domains_watch_audit')->where('id',$audit->id)->update(['reason'=>'Legacy plain reason']);
same('Legacy plain reason',$audit->fresh()->reason,'Existing plain audit remains readable');
DB::table('domains_watch_audit')->where('id',$audit->id)->update(['reason'=>null]);same(null,$audit->fresh()->reason,'Existing null reason stays null');
DB::table('domains_watch_audit')->where('id',$audit->id)->update(['reason'=>$raw]);
$encrypter=WatchAudit::currentEncrypter();WatchAudit::encryptUsing(new Illuminate\Encryption\Encrypter(str_repeat('z',32),'AES-256-CBC'));
try{$audit->fresh()->reason;expect(false,'Wrong key must not return encrypted data as plaintext');}catch(Illuminate\Contracts\Encryption\DecryptException){}finally{WatchAudit::encryptUsing($encrypter);}
same(false,$map->enabled,'New mapping disabled');same(1,$map->revision,'Initial revision');same(1,WatchAudit::count(),'Mapping saved in audit');
$staff->role()->first()->update(['permissions'=>[Staff::VIEW,'admin.domains.watch.view']]);
$denied=false;try{$writer->save($staff,$product->id,'domain',1,'Denied');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$denied=$e->getStatusCode()===403;}expect($denied,'Fresh manage required');
$staff->role()->first()->update(['permissions'=>[Staff::VIEW,'admin.domains.watch.view','admin.domains.watch.manage']]);
watchRefused(fn()=>$writer->save($staff,$product->id,'domain',0,'Stale edit'),'Old mapping revision');
foreach (['aliases','alias','domain-aliases','serverAlias','additional-domains','additional_domains','nested.domain','domains[]','domain,alias','',str_repeat('x',65)] as $key) {
    watchRefused(fn()=>$writer->save($staff,$product->id,$key,1,'Invalid key'),'Only exact primary scalar keys');
}
watchRefused(fn()=>$writer->save($staff,$product->id,'domain',1,' '),'Reason required');
same(1,$map->fresh()->revision,'Invalid map edits roll back');same(1,WatchAudit::count(),'Invalid map edits leave no audit');
$map=$writer->save($staff,$product->id,'domain',1,'Correct property');same(2,$map->revision,'Edit advances revision');same(false,$map->enabled,'Edit remains disabled');
watchRefused(fn()=>$map->fill(['enabled'=>true,'revision'=>3])->save(),'Enable is unavailable before its audited action');$map->refresh();
$domainServer=Server::create(['name'=>'Domain catalogue','extension'=>'Domains','type'=>'server','enabled'=>true]);
foreach ([null,$domainServer->id] as $i=>$serverId) {
    $invalidProduct=Product::create(['name'=>'Not hosting','slug'=>'non-hosting-'.$i,'category_id'=>$category->id,'server_id'=>$serverId,'hidden'=>true,'allow_quantity'=>'combined']);
    watchRefused(fn()=>$writer->save($staff,$invalidProduct->id,'domain',0,'Invalid product'),'Only hosting products can be mapped');
}
// Deliberate corrupt-route fixture uses the privileged maintenance boundary.
$catalogue=App\Models\Setting::withoutEvents(fn()=>$product->settings()->create(['key'=>'domains_catalogue','value'=>'true']));
watchRefused(fn()=>$writer->save($staff,$product->id,'domain',2,'Catalogue product'),'Catalogue product cannot be mapped');App\Models\Setting::withoutEvents(fn()=>$catalogue->delete());
$hash=str_repeat('a',64);$nameData=['user_id'=>$owner->id,'canonical_name'=>'example.com','psl_revision'=>$hash,'state'=>'active','notice_preferences'=>[]];
$refused=false;try{WatchName::create($nameData);}catch(DomainException $e){$refused=true;}expect($refused,'Model writes require enclosing transaction');
$watch=DB::transaction(fn()=>WatchName::create($nameData));
$another=DB::transaction(fn()=>WatchName::create([...$nameData,'user_id'=>$other->id]));
same(2,WatchName::where('canonical_name','example.com')->count(),'Canonical names are owner-scoped');
watchRefused(fn()=>WatchName::create($nameData),'Owner/name unique');
foreach ([['origin'=>'staff_manual'],['canonical_name'=>'Example.com'],['canonical_name'=>'127.0.0.1'],['canonical_name'=>'a_b.com'],['psl_revision'=>'bad'],['state'=>'unmanaged'],['registration'=>'unmanaged'],['expected_dns'=>'unmanaged'],['registration'=>'platform'],['state'=>'linked']] as $bad) {
    watchRefused(fn()=>WatchName::create([...$nameData,'canonical_name'=>'unused-placeholder.com',...$bad]),'Watch invariant');
}
$plain=WatchName::findOrFail($watch->id);$stale=WatchName::findOrFail($watch->id);
DB::transaction(fn()=>$plain->fill(['state'=>'paused','revision'=>2])->save());
watchRefused(fn()=>$stale->fill(['state'=>'retired','revision'=>2])->save(),'Stale watch update refused');
watchRefused(fn()=>$plain->fill(['state'=>'active'])->save(),'Revision must advance');
watchRefused(fn()=>$plain->fill(['user_id'=>$other->id,'revision'=>3])->save(),'Owner cannot reparent');
$watch->refresh();DB::transaction(fn()=>$watch->fill(['state'=>'active','revision'=>3,'registration_evidence'=>['source'=>'synthetic-public-fact']])->save());
expect(!str_contains(DB::table('domains_watch_names')->where('id',$watch->id)->value('registration_evidence'),'synthetic-public-fact'),'Registration evidence encrypted');
expect(!array_key_exists('registration_evidence',$watch->toArray()),'Evidence hidden from generic output');
$sourceData=['user_id'=>$owner->id,'service_id'=>$service->id,'map_id'=>$map->id,'map_revision'=>$map->revision,'hostname'=>'www.example.com','registrable_name'=>'example.com','watch_id'=>$watch->id,'state'=>'watch','source_digest'=>$hash,'last_seen_at'=>1800000000];
$source=DB::transaction(fn()=>WatchSource::create($sourceData));
watchRefused(fn()=>$source->increment('revision',1,['state'=>'retired','service_id'=>null]),'Numeric updates cannot bypass retained history');$source->refresh();
watchRefused(fn()=>$watch->decrement('revision',1,['state'=>'linked','registration'=>'platform']),'Numeric name updates cannot bypass validation');$watch->refresh();
watchRefused(fn()=>(new WatchName)->increment('revision'),'Unsaved numeric updates cannot become bulk writes');
watchRefused(fn()=>$source->fill(['hostname'=>'renamed.example.com','revision'=>2])->save(),'Live source hostname requires retirement');$source->refresh();
watchRefused(fn()=>$source->fill(['state'=>'held','watch_id'=>null,'safe_code'=>'source_missing','revision'=>2])->save(),'Live target changes require retained history');$source->refresh();
watchRefused(fn()=>WatchSource::create($sourceData),'Service source unique');
watchRefused(fn()=>$source->fill(['service_id'=>$service->id+999])->retire('Dirty service'),'Retirement rejects dirty service identity');$source->refresh();
watchRefused(fn()=>$source->fill(['hostname'=>'changed.example.com'])->retire('Dirty history'),'Retirement cannot overwrite saved metadata');$source->refresh();
$staleSource=$source->fresh();DB::transaction(fn()=>$source->fill(['revision'=>2,'last_seen_at'=>1800000001])->save());
watchRefused(fn()=>$staleSource->retire('Stale source'),'Retirement preserves optimistic revision check');
$source->retire('Owner changed');same(null,$source->fresh()->service_id,'Retirement releases active service key');same(null,$source->fresh()->watch_id,'Retirement clears watch target');
$audit=WatchAudit::where('event','source_retired')->firstOrFail();same($service->id,$audit->data['service_id'],'Retained original service association');same($watch->id,$audit->data['watch_id'],'Retained former watch target');
foreach (['hostname'=>'changed.example.com','registrable_name'=>'changed-placeholder.com','map_id'=>$map->id+1,'map_revision'=>999,'source_digest'=>str_repeat('b',64),'last_seen_at'=>1800000002] as $field=>$value) {
    $source->refresh();watchRefused(fn()=>$source->fill([$field=>$value,'revision'=>$source->revision+1])->save(),'Retired source history cannot change');
}
same($owner->id,$source->fresh()->user_id,'Retired owner retained');
$service->update(['user_id'=>$other->id]);
$new=DB::transaction(fn()=>WatchSource::create([...$sourceData,'user_id'=>$other->id,'watch_id'=>$another->id]));
expect($new->id!==$source->id,'Owner change allocates fresh source');same($owner->id,$watch->fresh()->user_id,'Old watch never reparented');
watchRefused(fn()=>$new->fill(['user_id'=>$owner->id,'revision'=>2])->save(),'Source owner immutable');
$new->refresh();watchRefused(fn()=>$new->fill(['state'=>'retired','service_id'=>null,'watch_id'=>null,'revision'=>2])->save(),'Direct unaudited retirement refused');
$new->refresh();$new->retire('Source removed');same(2,WatchSource::where('state','retired')->count(),'Multiple retired rows keep history');
// Held rows have no target and retain the current service identity.
foreach ([['hostname'=>'https://user:secret@example.com'],['registrable_name'=>'owner@example.com'],['safe_code'=>'unsafe message'],['safe_code'=>null]] as $bad) {
    watchRefused(fn()=>WatchSource::create([...$sourceData,'user_id'=>$other->id,'watch_id'=>null,'state'=>'held','safe_code'=>'source_missing',...$bad]),'Held sources store canonical names and safe codes only');
}
watchRefused(fn()=>WatchSource::create([...$sourceData,'user_id'=>$other->id,'watch_id'=>$another->id,'safe_code'=>'source_missing']),'Active sources cannot carry a hold code');
$held=DB::transaction(fn()=>WatchSource::create([...$sourceData,'user_id'=>$other->id,'watch_id'=>null,'hostname'=>null,'registrable_name'=>null,'state'=>'held','safe_code'=>'source_missing']));
$held->retire('Testing target invariants');
foreach ([['state'=>'held'],['state'=>'managed_link'],['state'=>'watch','watch_id'=>null],['state'=>'watch','managed_domain_id'=>123],['user_id'=>$owner->id],['watch_id'=>$watch->id],['map_revision'=>1],['source_digest'=>'bad']] as $bad) {
    watchRefused(fn()=>WatchSource::create([...$sourceData,'user_id'=>$other->id,'watch_id'=>$another->id,...$bad]),'Source target, owner, map and digest invariant');
}
// Managed links require matching ledger AND current native service ownership and never adopt pending holdings.
$account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$domainServer->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic-watch-binding']));
$binding=['server_id'=>$domainServer->id,'registrar_key'=>'enom','account_fingerprint'=>$account->account_fingerprint];
$managedOrder=Order::create(['user_id'=>$other->id,'currency_code'=>'USD']);
$managedService=Service::create(['user_id'=>$other->id,'order_id'=>$managedOrder->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
$domain=Domain::create([...$binding,'domain'=>'example.com','sld'=>'example','tld'=>'com','user_id'=>$other->id,'service_id'=>$managedService->id,'status'=>'active']);
$linkedData=[...$sourceData,'user_id'=>$other->id,'watch_id'=>null,'managed_domain_id'=>$domain->id,'state'=>'managed_link'];
$linked=DB::transaction(fn()=>WatchSource::create($linkedData));same($domain->id,$linked->managed_domain_id,'Owner-safe managed target');
// This fixture intentionally omits NativeDomainGuard to inject the otherwise forbidden owner race.
$linked->retire('Verify native mismatch');$managedService->update(['user_id'=>$owner->id]);
watchRefused(fn()=>WatchSource::create($linkedData),'Native and ledger customer must both match');$managedService->update(['user_id'=>$other->id]);
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'adoption_pending','provenance'=>'adoption_pending']);
watchRefused(fn()=>WatchSource::create($linkedData),'Adoption pending is not managed authority');
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'active','provenance'=>null]);
watchRefused(fn()=>WatchName::create([...$nameData,'user_id'=>$other->id,'canonical_name'=>'linked-placeholder.com','state'=>'linked','managed_domain_id'=>$domain->id]),'Linked tombstone exact canonical reservation');
foreach (['registration_rejected','transfer_rejected','cancelled','unknown'] as $failed) {
    DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>$failed]);
    watchRefused(fn()=>WatchSource::create($linkedData),'Failed holdings cannot suppress the public watch');
    watchRefused(fn()=>$another->fill(['state'=>'linked','managed_domain_id'=>$domain->id,'revision'=>$another->revision+1])->save(),'Failed holdings cannot create linked tombstones');$another->refresh();
}
foreach (['registration_pending','transfer_pending'] as $pending) {
    DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>$pending]);
    if($pending==='registration_pending'){
        foreach([['state'=>'paused'],['state'=>'active'],['display_name'=>'Reviewed external registration'],['ns_profile_id'=>42,'profile_revision'=>2],['notice_preferences'=>['dns'=>false]]]as$edit){
            $another->refresh();DB::transaction(fn()=>$another->fill([...$edit,'revision'=>$another->revision+1])->save());
            foreach($edit as$field=>$value){same($value,$another->fresh()->$field,'Existing watch can update '.$field.' during acquisition');}
        }
    }
    $progress=DB::transaction(fn()=>WatchSource::create($linkedData));$progress->retire('Pending acquisition source progress verified');
    watchRefused(fn()=>$another->fill(['state'=>'linked','managed_domain_id'=>$domain->id,'registration'=>'platform','revision'=>$another->revision+1])->save(),'Pending acquisition cannot claim platform registration');
    $another->refresh();DB::transaction(fn()=>$another->fill(['state'=>'linked','managed_domain_id'=>$domain->id,'registration'=>null,'revision'=>$another->revision+1])->save());
    same(null,$another->fresh()->registration,'Pending acquisition keeps read-only progress without platform attribution');
    watchRefused(fn()=>$another->fill(['state'=>'active','managed_domain_id'=>null,'revision'=>$another->revision+1])->save(),'Pending managed acquisition cannot restart an external watch');$another->refresh();
}
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'registration_rejected']);
DB::transaction(fn()=>$another->fill(['state'=>'active','managed_domain_id'=>null,'registration'=>null,'revision'=>$another->revision+1])->save());
same('active',$another->fresh()->state,'Failed acquisition permits external watching again');
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'active']);
DB::transaction(fn()=>$another->fill(['state'=>'linked','managed_domain_id'=>$domain->id,'registration'=>'platform','revision'=>$another->revision+1])->save());same('linked',$another->fresh()->state,'Existing watch becomes retained linked tombstone');
foreach (['grace','expired'] as $managedStatus) {
    DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>$managedStatus]);
    DB::transaction(fn()=>$another->fill(['revision'=>$another->revision+1])->save());
    $managedSource=DB::transaction(fn()=>WatchSource::create($linkedData));$managedSource->retire('Managed holding remains linked');
    same('platform',$another->fresh()->registration,'Managed grace and expired holdings retain platform registration');
}
foreach ([$watch,$map,$source,$audit] as $row) {watchRefused(fn()=>$row->delete(),'History cannot delete');}
// All three retained reason models share the versioned ciphertext contract.
$profile=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create(['name'=>'Synthetic profile','nameservers'=>['ns1.example.test'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$staff->id,'reason'=>'Profile reason']));
foreach([$map->fresh(),$audit->fresh(),$profile]as$row){
    $table=$row->getTable();$id=$row->id;$original=$row->getRawOriginal('reason');$reason=$row->reason;
    expect(str_starts_with($original,'encrypted:v1:'),'New '.$table.' ciphertext is versioned');
    $old=\Illuminate\Support\Facades\Crypt::encryptString($reason);
    foreach([$old=>$reason,'Legacy retained reason'=>'Legacy retained reason']as$bytes=>$expected){
        DB::table($table)->where('id',$id)->update(['reason'=>$bytes]);same($expected,$row->fresh()->reason,'Old ciphertext and plaintext stay readable in '.$table);
        same($bytes,DB::table($table)->where('id',$id)->value('reason'),'Legacy reads never rewrite retained bytes');
    }
    foreach(['encrypted:v1:','encrypted:v1:'.substr($old,0,12)]as$damaged){
        DB::table($table)->where('id',$id)->update(['reason'=>$damaged]);$refused=false;
        try{$row->fresh()->reason;}catch(Illuminate\Contracts\Encryption\DecryptException){$refused=true;}
        expect($refused,'Truncated versioned '.$table.' ciphertext cannot become plaintext');
    }
    foreach([$original,$old]as$bytes){
        DB::table($table)->where('id',$id)->update(['reason'=>$bytes]);
        $encrypter=$row::currentEncrypter();$row::encryptUsing(new Illuminate\Encryption\Encrypter(str_repeat('z',32),'AES-256-CBC'));
        $refused=false;try{$row->fresh()->reason;}catch(Illuminate\Contracts\Encryption\DecryptException){$refused=true;}finally{$row::encryptUsing($encrypter);}
        expect($refused,'Wrong key refuses old and versioned '.$table.' ciphertext');
    }
    DB::table($table)->where('id',$id)->update(['reason'=>$original]);
}
$entry->upgraded('0.1.0');$entry->uninstalled();WatchSchema::verify();
DB::table('migrations')->where('migration','2026_10_19_000009_create_watch_storage')->delete();
$refused=false;try{$entry->installed();}catch(DomainException $e){$refused=str_contains($e->getMessage(),'watch storage history');}expect($refused,'Missing watch migration history refuses reinstall');
$m=require dirname(__DIR__).'/Domains/database/migrations/2026_10_19_000009_create_watch_storage.php';$m->down();WatchSchema::verify();
same(8,WatchSource::count(),'Down retains all sources');same(2,WatchName::count(),'Down retains owner names');
Schema::drop('domains_watch_sources');$refused=false;try{WatchSchema::install();}catch(RuntimeException $e){$refused=str_contains($e->getMessage(),'Partial');}expect($refused,'Partial retained schema refuses repair');
echo "Watch storage owner identities, source target invariants, revisions, disabled mappings, encrypted evidence and retained history passed.\n";$nativeSuccessful=true;
