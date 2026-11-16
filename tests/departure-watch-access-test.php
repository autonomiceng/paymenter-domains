<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic owner-scoped watch access.
declare(strict_types=1);
require __DIR__.'/departure-watch-test.php';
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;

$nativeSuccessful=false;
foreach(['PslSchema','PslReviewSchema','WatchSchema','WatchDnsSchema','ManagedPublicWorkSchema','ManagedPublicSchema','WatchScheduleSchema','WatchNoticeSchema','AdoptionSchema','NativeIntentSchema'] as $schema){('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$schema)::install();}
require_once dirname(__DIR__).'/Domains/routes/web.php';require_once dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$summaries=new DomainSummaries;$user=User::findOrFail($owner);$now=$clock->now()->getTimestamp();
expect($summaries->one($user,'watch',(int)$watch->id,$now)!==null,'The owner can open the handed-off watch');
$request=Illuminate\Http\Request::create('/domains/checks/watch/'.$watch->id);$request->setUserResolver(static fn()=>$user);
$request->setLaravelSession(app('session')->driver('array'));
$view=app(Paymenter\Extensions\Servers\Domains\Http\Controllers\WatchNoticeController::class)->show($request,'watch',(int)$watch->id);
same($name,$view->getData()['summary']->name,'The watch detail controller serves the handed-off name');
$rows=$summaries->listed($user,$now);
same([true,false],[isset($rows['managed:'.$d->id]),isset($rows['watch:'.$watch->id])],'The customer list retains one row for the departed name');
same(null,$summaries->one($staff,'watch',(int)$watch->id,$now),'Another owner cannot open the handed-off watch');
// A distinct live holding of this owner still takes precedence over its same-name watch.
$row=DB::table('domains_domains')->where('id',$d->id)->first();$row=(array)$row;unset($row['id']);
$liveService=$d->fresh()->service->replicate();$liveService->status='active';$liveService->saveQuietly();
DB::table('domains_domains')->insert([...$row,'service_id'=>$liveService->id,'status'=>'active','reservation_name'=>$name,'renewal_policy'=>null]);
same(null,$summaries->one($user,'watch',(int)$watch->id,$now),'A live managed holding still suppresses the duplicate watch');
echo "A departed holding keeps its watch directly reachable without adding a duplicate list row.\n";
$nativeSuccessful=true;
