<?php
// Synthetic current public evidence and native queue only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/watch-dns.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticeTemplates,WatchRegistryChecks,WatchDnsChecks,WatchNotices,WatchNoticeDelivery};
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
function noticeFixture(): array
{
    $f=dnsFixture();extract($f,EXTR_SKIP);global $fixture;require __DIR__.'/reminder-mail.php';
    app('view')->prependNamespace('domains',dirname(__DIR__,2).'/Domains/resources/views');\Paymenter\Extensions\Servers\Domains\Services\WatchNoticeSchema::install();WatchNoticeTemplates::ensure();
    DB::table('domains_watch_profiles')->where('id',$profile->id)->update(['enabled'=>true]);
    $now=1800000000;$id=(int)$watch->id;$uid=(int)$owner->id;
    $registry=new WatchRegistryChecks(true);$dns=new WatchDnsChecks(true);$planner=new WatchNotices(true);$delivery=new WatchNoticeDelivery(true);
    $registry->enroll($id,$uid,$now);$dns->enroll($id,$uid,$now);
    return [...$f,...compact('now','id','uid','registry','dns','planner','delivery')];
}
function noticeRegistry(array $f,int $now,int $days=7): void
{
    extract($f,EXTR_SKIP);DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['due_at'=>$now,'backoff_until'=>null]);
    $t=$registry->begin($id,$uid,$now);expect($t!==null,'Registry notice fixture ticket');
    expect($registry->finish($t,['result'=>'facts','reason'=>'facts','safe_code'=>null,'expiry'=>gmdate('Y-m-d',$now+$days*86400),
        'precision'=>'date','source'=>'https://rdap.example.com','status'=>[]],str_repeat('c',64),$now+1),'Fresh registry fixture');
}
function noticeDns(array $f,int $now,string $value='external'): void
{
    extract($f,EXTR_SKIP);DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','delegation')->update(['due_at'=>$now,'backoff_until'=>null]);
    $t=$dns->begin($id,$uid,'delegation',$now);expect($t!==null,'DNS notice fixture ticket');
    expect($dns->finish($t,new DnsReading('delegation',$value),$now+1),'Fresh DNS fixture');
}
