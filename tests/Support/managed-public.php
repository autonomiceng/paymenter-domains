<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/adoption-confirm.php';
require __DIR__.'/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption,WatchProfile,WatchName,WatchSource,WatchProductMap};
use Paymenter\Extensions\Servers\Domains\Services\{WatchDnsSchema,ManagedPublicWorkSchema,ManagedPublicSchema,ManagedPublicSignals};
use Paymenter\Extensions\Servers\Domains\Tests\FakeDnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsQuery};
function managedFixture(bool $doNotRenew=false,bool $hosting=true,bool $skipA3=false,?Closure $beforeConfirm=null): array
{
    $f=adoptionCommitFixture();extract($f);WatchDnsSchema::install();ManagedPublicWorkSchema::install();ManagedPublicSchema::install();
    $profile=DB::transaction(fn()=>WatchProfile::create(['name'=>'Synthetic managed defaults','nameservers'=>['ns.platform.test'],
        'website_endpoints'=>['ips'=>['192.0.2.1']],'email_endpoints'=>['exact'=>['mx.platform.test']],'edited_by'=>$staff->id,'reason'=>'Synthetic profile']));
    DB::transaction(fn()=>$profile->fill(['enabled'=>true,'revision'=>2,'reason'=>'Synthetic accepted composition'])->save());
    $watch=null;$source=null;$host=null;$map=null;
    if($hosting) {
        $server=App\Models\Server::create(['name'=>'Hosting','extension'=>'SyntheticHosting','type'=>'server','enabled'=>true]);
        $hp=App\Models\Product::create(['name'=>'Hosting','slug'=>'managed-host','category_id'=>$product->category_id,'server_id'=>$server->id,'hidden'=>true,'allow_quantity'=>'combined']);
        $plan=$hp->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
        $o=App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
        $host=App\Models\Service::create(['user_id'=>$owner->id,'order_id'=>$o->id,'product_id'=>$hp->id,'plan_id'=>$plan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
        $host->properties()->create(['key'=>'domain','value'=>'www.example.com']);
        $map=DB::transaction(fn()=>WatchProductMap::create(['product_id'=>$hp->id,'property_key'=>'domain','expected_ns_profile_id'=>$profile->id,'edited_by'=>$staff->id,'reason'=>'Synthetic map']));
        DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true]);
        $watch=DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'example.com','psl_revision'=>str_repeat('a',64),
            'state'=>'active','ns_profile_id'=>$profile->id,'profile_revision'=>2,'expected_dns'=>'platform','notice_preferences'=>['expiry'=>true,'dns'=>true]]));
        $source=DB::transaction(fn()=>WatchSource::create(['user_id'=>$owner->id,'service_id'=>$host->id,'map_id'=>$map->id,'map_revision'=>1,
            'hostname'=>'www.example.com','registrable_name'=>'example.com','watch_id'=>$watch->id,'state'=>'watch','source_digest'=>str_repeat('b',64),'last_seen_at'=>$clock->now()->getTimestamp()]));
    }
    if ($watch) {
        $checks=new Paymenter\Extensions\Servers\Domains\Services\WatchDnsChecks(true);$old=$clock->now()->getTimestamp()-7200;
        expect($checks->enroll($watch->id,$owner->id,$old),'Prior watch enrolled');$ticket=$checks->begin($watch->id,$owner->id,'delegation',$old);
        expect($checks->finish($ticket,new Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external'),$old+1),'Prior watch facts retained');
    }
    if ($beforeConfirm) { $beforeConfirm([...$f,...compact('watch','source','profile','host','map')]); }
    $failA3=$skipA3;
    Paymenter\Extensions\Servers\Domains\Models\WatchAudit::creating(static function($audit)use(&$failA3){if($failA3 && $audit->event==='registration_adopted'){throw new DomainException('Synthetic lost A3 enrollment');}});
    $context=new Paymenter\Extensions\Servers\Domains\Data\Adoption\StaffContext($staff->id,'Synthetic managed fixture',$doNotRenew);
    $r=$reader->preview($target,$context);same('ready',$r->code,'Managed fixture preview');
    $a=Adoption::findOrFail($r->previewId);$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
    $failA3=false;
    $time=(object)['now'=>$clock->now()->getTimestamp()];$fake=new FakeDnsReader();
    $signals=new ManagedPublicSignals($fake,true,fn()=>$time->now,$profile->id);
    return [...$f,...compact('d','profile','watch','source','host','map','time','fake','signals')];
}
function managedNs(string $host='ns.platform.test',int $ttl=300): DnsAnswer
{ return DnsAnswer::rrset(new DnsQuery('example.com','NS'),[['host'=>'example.com','class'=>'IN','type'=>'NS','target'=>$host,'ttl'=>$ttl]]); }
function managedDue(int $id,int $now,string $kind='delegation'): void
{ DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('kind',$kind)->update(['due_at'=>$now,'backoff_until'=>null]); }
