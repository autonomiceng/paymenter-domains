<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Services\WatchNoticePlan as Plan;
$base=strtotime('2030-01-01 UTC');
function expiry(int $now,int $days,array $extra=[]): array
{ return $extra+['id'=>1,'at'=>$now,'valid_until'=>$now+172800,'current'=>true,'complete'=>true,'expiry'=>gmdate('Y-m-d',$now+$days*86400)]; }
foreach(Plan::WINDOWS as $days){
    $p=Plan::expiry([],expiry($base,$days),$base);same(1,count($p['notices']),'One exact boundary');same($days,$p['notices'][0]['threshold'],'Exact UTC threshold');
    same([] ,Plan::expiry($p['state'],expiry($base,$days),$base)['notices'],'Same sample cannot replay');
}
foreach([89=>90,59=>60,45=>60,29=>30,13=>14,6=>7,0=>0,-10=>0] as $days=>$window){
    $p=Plan::expiry([],expiry($base,$days),$base);same([$window],array_column($p['notices'],'threshold'),'Nearest-only discovery');
}
same([],Plan::expiry([],expiry($base,91),$base)['notices'],'Future window never consumed');
$f=expiry($base,100);$p=Plan::expiry([],$f,$base);
$late=$base+45*86400;$f['at']=$late;$f['valid_until']=$late+172800;
same([],Plan::expiry($p['state'],$f,$late)['notices'],'Missed windows do not flood');
foreach([0,1,2,3] as $lateDays){
    $now=$base+(10+$lateDays)*86400;$f=expiry($base,100);$f['at']=$now;$f['valid_until']=$now+172800;
    $r=Plan::expiry($p['state'],$f,$now);same($lateDays<=2?1:0,count($r['notices']),'Catch-up at most two UTC days');
}
$one=Plan::expiry([],expiry($base,30),$base);$extended=Plan::expiry($one['state'],expiry($base+86400,100),$base+86400);
same('2030-01-31',$extended['resolve'],'Extension resolves predecessor');same([], $extended['notices'],'Extended future expiry waits');
$corrected=Plan::expiry($one['state'],expiry($base+3600,5),$base+3600);same([],$corrected['notices'],'Earlier correction limited per 24h');
$corrected=Plan::expiry($corrected['state'],expiry($base+86400,4),$base+86400);same([7],array_column($corrected['notices'],'threshold'),'Urgent correction next day');
$flip=Plan::expiry($corrected['state'],expiry($base+172800,28),$base+172800);same([],$flip['notices'],'Retained same-date keys stop flip-flop duplicates');
$reversed=Plan::expiry($one['state'],expiry($base-1,1),$base-1);same($one['state'],$reversed['state'],'Reversal never consumes future keys');same('clock_reversal',$reversed['health'],'Reversal staff health signal');
foreach([['current'=>false],['complete'=>false],['valid_until'=>$base],['at'=>$base+1],['expiry'=>'2030-02-30'],['expiry'=>null]] as $bad){
    $r=Plan::expiry([],expiry($base,7,$bad),$base);same([],$r['notices'],'Unknown/stale/wrong revision cannot notify');same(['expiry_unavailable'],$r['portal'],'Unavailable portal condition');
}
function ns(int $now,string $dns='external',int $id=1,array $extra=[]): array
{ return $extra+['id'=>$id,'at'=>$now,'valid_until'=>$now+43200,'dns'=>$dns,'lineage'=>'profile:1','complete'=>true,'current'=>true,'ns_confirmed'=>false]; }
$p=Plan::dns([],ns($base),$base,true);same(['ns_pending'],$p['portal'],'First mismatch pending');same([],$p['notices'],'One mismatch cannot send');
same([],Plan::dns($p['state'],ns($base+10800,'external',2),$base+10800,true)['notices'],'Never invent confirmation after observer gap');
$drift=Plan::dns($p['state'],ns($base+900,'external',2,['ns_confirmed'=>true]),$base+900,true);same(['ns_changed'],array_column($drift['notices'],'kind'),'Observer two-sample proof opens one episode');
$long=Plan::dns($drift['state'],ns($base+50000,'external',3),$base+50000,true);same(['ns_changed'],$long['portal'],'Open drift remains visible after proof expires');same([],$long['notices'],'No repeated drift');
$match=Plan::dns($drift['state'],ns($base+1800,'platform',3),$base+1800,true);same([], $match['notices'],'First matching sample cannot resolve');same(['ns_changed'],$match['portal'],'Open portal survives first matching sample');
$restored=Plan::dns($match['state'],ns($base+2700,'platform',4,['ns_confirmed'=>true]),$base+2700,true);same([], $restored['notices'],'Undelivered drift never restoration mail');expect(isset($restored['resolve']),'Two matching samples resolve');
$match['state']['delivered']=['mail'];$restored=Plan::dns($match['state'],ns($base+2700,'platform',4,['ns_confirmed'=>true]),$base+2700,true);
same(['ns_restored'],array_column($restored['notices'],'kind'),'Delivered drift permits restoration');same(['mail'],$restored['notices'][0]['channels'],'Restoration limited to previous channels');
same([],Plan::dns($restored['state'],ns($base+3600,'platform',5,['ns_confirmed'=>true]),$base+3600,true)['notices'],'Restoration once');
foreach([['current'=>false],['complete'=>false],['dns'=>null],['valid_until'=>$base+900]] as $bad){
    $r=Plan::dns($drift['state'],ns($base+900,'platform',3,$bad),$base+900,true);same($drift['state'],$r['state'],'Unknown neither confirms nor clears');
}
$profile=Plan::dns($drift['state'],ns($base+1800,'external',3,['profile_revision'=>2]),$base+1800,true);same([], $profile['notices'],'Unconfirmed revision cannot open another episode');same($drift['state']['episode'],$profile['state']['episode'],'Revision retains episode identity');
$relinked=Plan::relinked($drift['state']+['sample'=>['id'=>1]]);$relinked['delivered']=['mail'];expect(!isset($relinked['sample'],$relinked['seen_at']),'Relink drops predecessor proof');
$r=Plan::dns($relinked,ns($base+1800,'external',2),$base+1800,true);same([],$r['notices'],'Managed observation ID collision never replays drift');
$r=Plan::dns($r['state'],ns($base+2700,'platform',3),$base+2700,true);same([],$r['notices'],'Managed first match cannot borrow watch proof');
$r=Plan::dns($r['state'],ns($base+3600,'platform',4,['ns_confirmed'=>true]),$base+3600,true);same(['ns_restored'],array_column($r['notices'],'kind'),'Managed own confirmation restores predecessor episode');
same([],Plan::dns([],ns($base),$base,false)['notices'],'Unexpected external delegation not drift');
expect(isset(Plan::dns($drift['state'],null,$base+1800,false)['resolve']),'Changed expectation retires drift without restoration');
expect(Plan::key(1,'example.com','','date','expiring',90)!==Plan::key(1,'example.com','','date','expiring',60),'Expiry thresholds have distinct identities');
expect(Plan::key(1,'example.com','p1','e','ns_changed',null,'mail')!==Plan::key(1,'example.com','p1','e','ns_changed',null,'app'),'Per-channel identity');
expect(Plan::key(1,'example.com','p1','e','ns_changed')!==Plan::key(2,'example.com','p1','e','ns_changed'),'Never dedup across owners');
$past=Plan::expiry($one['state'],expiry($base+3600,-4),$base+3600);$past=Plan::expiry($past['state'],expiry($base+86400,-5),$base+86400);same([0],array_column($past['notices'],'threshold'),'Rate-limited expired correction survives');
$early=Plan::expiry([],expiry($base,30),$base);$late=Plan::expiry($early['state'],expiry($base+86400,100),$base+86400);$back=Plan::expiry($late['state'],expiry($base+172800,28),$base+172800);expect(!$back['state']['correction_pending'],'Already-keyed correction clears pending');
$miss=Plan::expiry($back['state'],expiry($base+20*86400,10),$base+20*86400);same([],$miss['notices'],'Retained correction cannot bypass later catch-up');
$midnight=Plan::expiry([],expiry($base-1,8,['expiry'=>gmdate('Y-m-d',$base+7*86400)]),$base-1);same([14],array_column($midnight['notices'],'threshold'),'Before UTC midnight uses previous calendar day');
$midnight=Plan::expiry($midnight['state'],expiry($base,7),$base);same([7],array_column($midnight['notices'],'threshold'),'UTC midnight crosses next boundary');
$far=Plan::expiry([],expiry($base,200),$base);$far=Plan::expiry($far['state'],expiry($base+1,199),$base+1);expect(!$far['state']['correction_pending'],'Far-future correction never stays pending');
$far=Plan::expiry($far['state'],expiry($base+114*86400,85),$base+114*86400);same([],$far['notices'],'Far correction obeys catch-up limit');
$expired=Plan::expiry([],expiry($base,-3),$base);$expiredAgain=Plan::expiry($expired['state'],expiry($base+3600,-1),$base+3600);same([],$expiredAgain['notices'],'No recurring expired mail on past-date correction');
same(['registry_expiry_reached','expiry_unavailable'],Plan::expiry($expired['state'],null,$base+86400)['portal'],'Expired portal survives outage');
same(['ns_changed','dns_unavailable'],Plan::dns($drift['state'],null,$base+1800,true)['portal'],'Drift portal survives unknown');
same($drift['state']['episode'],Plan::dns($drift['state'],ns($base+1800,'external',3,['lineage'=>null]),$base+1800,true)['state']['episode'],'Missing lineage cannot clear episode');
same([],Plan::dns($drift['state'],null,$base+1800,false)['portal'],'Retired expectation clears portal');
same([],Plan::dns($drift['state'],ns($base+1800,'platform',3,['lineage'=>'profile:2']),$base+1800,true)['portal'],'Changed lineage clears predecessor portal');
$reached=Plan::expiry([],expiry($base,0),$base);same(['registry_expiry_reached'],Plan::expiry($reached['state'],null,$base-1)['portal'],'Reversal across expiry retains reached condition');
$outage=Plan::expiry([],expiry($base,1),$base);$outage=Plan::expiry($outage['state'],null,$base+86400);$outage=Plan::expiry($outage['state'],null,$base+43200);
same(['registry_expiry_reached'],$outage['portal'],'Outage crossing retained across reversal without evaluating reversed freshness');same('clock_reversal',$outage['health'],'Outage evaluation has high-water mark');
$dnsOutage=Plan::dns($p['state'],null,$base+86400,true);$dnsOutage=Plan::dns($dnsOutage['state'],ns($base+900,'external',2,['ns_confirmed'=>true]),$base+900,true);
same([],$dnsOutage['notices'],'DNS outage reversal cannot consume episode');same('clock_reversal',$dnsOutage['health'],'DNS evaluation high-water mark detects reversal');
echo "Watch UTC windows, bounded catch-up, correction keys, clock reversal and DNS episodes passed.\n";
