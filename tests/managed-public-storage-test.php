<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Dns\PublicSignalsTarget;
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicObservation,ManagedPublicConfig,WatchName};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,ManagedPublicSchema,ManagedPublicWorkSchema};
extract(dnsFixture()); $now = 1800000000;
ManagedPublicSchema::verify();
$managed = PublicSignalsTarget::managed(55, $owner->id, str_repeat('d',64), 2, 3);
same('managed',$managed->kind,'Managed discriminant'); same(3,$managed->revision,'Domain revision retained');
same('watch',PublicSignalsTarget::watch($watch->id,$owner->id,1)->kind,'External discriminant');
dnsRefused(fn()=>PublicSignalsTarget::managed(1,1,'bad',1,0),'Invalid target account');
dnsRefused(fn()=>PublicSignalsTarget::managed(1,1,str_repeat('a',64),0,0),'Invalid target generation');
DB::table('domains_domains')->insert(['id'=>55,'service_id'=>$service->id,'user_id'=>$owner->id,'server_id'=>$server->id,'registrar_key'=>'enom',
    'account_fingerprint'=>str_repeat('a',64),'domain'=>'managed.test','reservation_name'=>'managed.test','sld'=>'managed','tld'=>'test','status'=>'active']);
dnsRefused(fn()=>ManagedPublicConfig::create(['domain_id'=>55,'user_id'=>$other->id,'notice_preferences'=>[]]),'Config cannot claim another owner holding');
$count=WatchName::count();
DB::transaction(function()use($owner,$now,$profile):void {
    ManagedPublicConfig::create(['domain_id'=>55,'user_id'=>$owner->id,'notice_preferences'=>[]]);
    DB::table(ManagedPublicWorkSchema::TABLE)->insert(['domain_id'=>55,'user_id'=>$owner->id,'generation'=>2,
        'account_fingerprint'=>str_repeat('d',64),'kind'=>'delegation','due_at'=>$now,'target_stamp'=>str_repeat('a',64)]);
    ManagedPublicObservation::create(['domain_id'=>55,'user_id'=>$owner->id,'account_fingerprint'=>str_repeat('d',64),'generation'=>2,
        'domain_revision'=>3,'config_revision'=>1,'profile_id'=>$profile->id,'profile_revision'=>$profile->revision,'source_digest'=>str_repeat('a',64),'check_kind'=>'delegation','started_at'=>$now,
        'completed_at'=>$now+1,'valid_until'=>$now+43200,'result'=>'complete','dns'=>'external','mixed'=>false,'partial'=>false,
        'facts'=>['version'=>1,'dns'=>'external'],'digest'=>str_repeat('b',64)]);
});
DB::transaction(function():void {
    $config=ManagedPublicConfig::findOrFail(55); $config->expected_dns='external'; $config->revision++; $config->save();
});
same(2,ManagedPublicConfig::findOrFail(55)->revision,'Configuration updates lock its domain primary key');
same($count,WatchName::count(),'Managed storage creates no watch row');
$row=DB::table(ManagedPublicWorkSchema::TABLE)->sole();
same('awaiting_acceptance',$row->safe_code,'Original dormant default retained'); same(null,$row->claim,'Old enrollment unclaimed');
same(null,$row->domain_revision,'Old enrollment remains unstamped until accepted reconciliation');
$fact=ManagedPublicObservation::sole(); same($now,$fact->started_at,'Managed timestamp independent of watches');
expect(!str_contains($fact->getRawOriginal('facts'),'external'),'Managed facts encrypted'); expect(!isset($fact->toArray()['facts']),'Managed facts hidden');
expect(!isset($fact->toArray()['account_fingerprint']),'Account binding hidden');
dnsRefused(fn()=>ManagedPublicObservation::create([...$fact->getAttributes(),'id'=>null,'profile_id'=>null,'facts'=>$fact->facts]),'No profile cannot produce known facts');
$unknown=DB::transaction(fn()=>ManagedPublicObservation::create([...$fact->getAttributes(),'id'=>null,'profile_id'=>null,'profile_revision'=>null,
    'result'=>'unknown','dns'=>null,'website'=>null,'email'=>null,'valid_until'=>null,'partial'=>true,'facts'=>['version'=>1]]));
same('unknown',$unknown->fresh()->result,'Profileless unknown is stored successfully');
dnsRefused(fn()=>ManagedPublicObservation::create([...$unknown->getAttributes(),'id'=>null,'ns_confirmed'=>true,'facts'=>$unknown->facts]),'Unknown DNS cannot be confirmed');
dnsRefused(fn()=>$fact->fill(['dns'=>'platform'])->save(),'Managed facts immutable'); dnsRefused(fn()=>$fact->delete(),'Managed history retained');
$attrs=['user_id'=>$owner->id,'canonical_name'=>'managed.test','psl_revision'=>str_repeat('a',64),'state'=>'active','notice_preferences'=>[]];
dnsRefused(fn()=>WatchName::create($attrs),'Same owner managed holding refuses an active external watch');
DB::transaction(fn()=>WatchName::create([...$attrs,'user_id'=>$other->id]));
DB::table('domains_domains')->where('domain','managed.test')->update(['provenance'=>'adoption_pending']);
DB::transaction(fn()=>WatchName::create($attrs));
$original=DB::table('domains_managed_public_observations')->value('facts');
$m=require dirname(__DIR__).'/Domains/database/migrations/2026_10_19_000030_extend_managed_public_signals.php';$m->down();
$entry->upgraded('0.1.0');$entry->uninstalled();
same($original,DB::table('domains_managed_public_observations')->value('facts'),'Lifecycle retains ciphertext');
expect(in_array(ManagedPublicSchema::class,Installation::SCHEMAS,true),'Combined verifier includes managed signals');
DB::table('migrations')->where('migration','2026_10_19_000030_extend_managed_public_signals')->delete();
dnsRefused(fn()=>$entry->installed(),'Missing upgrade history refuses reinstall',false);
Schema::drop('domains_public_dns_pool');dnsRefused(fn()=>ManagedPublicSchema::install(),'Partial retained upgrade is not repaired');
$nativeSuccessful=true;echo "Managed public storage, dormant enrollment, target union and retained lifecycle passed.\n";
