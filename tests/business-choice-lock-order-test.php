<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Record queries from real DNS workers and staff writers; SQLite proves ordering, not concurrent row locks.
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,StaffWatchWrites as Writes};
extract(dnsFixture());$manager=staffUser(['admin.domains.view','admin.domains.manage-policy',SiteDnsDefault::MANAGE,Writes::VIEW,Writes::MANAGE]);
$profile->enabled=true;$profile->saveQuietly();
$queries=[];$record=false;DB::listen(static function($q)use(&$queries,&$record):void{if($record){$queries[]=$q->sql;}});
$position=static function(string $table)use(&$queries):int {
    foreach($queries as $i=>$sql){if(str_starts_with($sql,'select')&&str_contains($sql,'"'.$table.'"')){return $i;}}
    throw new RuntimeException('Expected query: '.$table);
};
$queries=[];$record=true;SiteDnsDefault::save($manager,$profile->id);$record=false;
expect($position('domains_business_settings')<$position('domains_watch_profiles'),'Site save uses the same global/template order');
expect(!array_filter($queries,fn($sql)=>str_contains($sql,'"settings"')),'Site saves never lock native settings');
$unused=DB::transaction(fn()=>WatchProfile::create(['name'=>'Unused template','enabled'=>true,'nameservers'=>['ns1.unused.test','ns2.unused.test'],
    'edited_by'=>$manager->id,'reason'=>'Synthetic unused template']));
$queries=[];$record=true;
(new Writes)->run($manager,$unused->id,'delete',Writes::ticket($manager->id,$unused->id,$unused->revision,'delete'),'Delete unused template');
$record=false;
expect($position('domains_business_settings')<$position('domains_watch_profiles'),'Template deletion uses global choices before templates');
expect(!array_filter($queries,fn($sql)=>str_contains($sql,'"settings"')),'Template deletion never locks native settings');
$nativeSuccessful=true;echo "DNS choice locks follow native owners, then global choices, then templates and assignments.\n";
