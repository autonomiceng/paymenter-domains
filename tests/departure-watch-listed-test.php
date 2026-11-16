<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic retained acquisition and watch identities.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;

$nativeSuccessful=false;
foreach(['PslSchema','PslReviewSchema','WatchSchema','WatchDnsSchema','ManagedPublicWorkSchema','ManagedPublicSchema','WatchScheduleSchema','WatchNoticeSchema','AdoptionSchema','NativeIntentSchema'] as $schema){('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$schema)::install();}
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$watch=static fn(string $name)=>DB::transaction(fn()=>WatchName::create(['user_id'=>$user->id,'canonical_name'=>$name,
    'state'=>'active','psl_revision'=>str_repeat('a',64),'notice_preferences'=>[]]));
$unpaidName=placeholderComFixtureName('unpaid-watch-placeholder.com');
$unpaidWatch=$watch($unpaidName);
$unpaid=$new($unpaidName,paid:false);
$cancel($unpaid);
$paid=$new(placeholderComFixtureName('paid-watch-placeholder.com'));
DB::table('services')->where('id',$paid->service_id)->update(['status'=>'cancelled']);
DB::table('domains_domains')->where('id',$paid->domain_id)->update(['status'=>'cancelled']);
$paidWatch=$watch($paid->domain->domain);
$summaries=new DomainSummaries;$now=$clock->now()->getTimestamp();
Auth::setUser(staffUser(['admin.domains.view','admin.domains.watch.view']));
foreach([false,true] as $retired){
    if($retired){$retirement->inspect((int)$unpaid->domain_id);}
    $rows=$summaries->listed($user,$now);
    same(true,isset($rows['watch:'.$unpaidWatch->id]),'An active watch survives a same-name cancelled unpaid order');
    same(false,isset($rows['managed:'.$unpaid->domain_id]),'The never-paid managed order stays out of the customer list');
    same([true,false],[isset($rows['managed:'.$paid->domain_id]),isset($rows['watch:'.$paidWatch->id])],'A retained paid managed summary suppresses a duplicate watch');
    expect($summaries->one($user,'watch',(int)$unpaidWatch->id,$now)!==null,'The listed watch can also be opened directly');
    same(null,$summaries->one($user,'watch',(int)$paidWatch->id,$now),'The paid managed summary also suppresses the direct watch');
    $staffRows=$summaries->staffWatches([$unpaidWatch->id,$paidWatch->id],$now);
    same([true,false],[isset($staffRows['watch:'.$unpaidWatch->id]),isset($staffRows['watch:'.$paidWatch->id])],'Staff suppression also ignores cancelled unpaid orders');
}
echo "The customer keeps an active watch when the same-name managed order was never paid.\n";
$nativeSuccessful=true;
