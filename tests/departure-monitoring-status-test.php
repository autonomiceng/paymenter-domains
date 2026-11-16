<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic watch lifecycle only.
declare(strict_types=1);
require __DIR__.'/departure-watch-test.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Presentation\DepartedOwnerPage;

$nativeSuccessful=false;
require_once dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$monitoring=static fn()=>array_values(array_filter(DepartedOwnerPage::of($d->fresh(),null)['rows'],
    static fn($row)=>isset($row['link'][1])&&str_contains($row['link'][1],'/checks/watch/')));
same(1,count($monitoring()),'The running handed-off watch is linked');
$opens=static function()use($d,$watch):bool{
    $request=Illuminate\Http\Request::create('/checks/watch/'.$watch->id);$request->setUserResolver(fn()=>$d->user()->firstOrFail());
    $request->setLaravelSession(app('session')->driver('array'));view()->addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
    try{app(Paymenter\Extensions\Servers\Domains\Http\Controllers\WatchNoticeController::class)->show($request,'watch',(int)$watch->id);return true;}
    catch(Symfony\Component\HttpKernel\Exception\HttpException $e){return false;}
};
expect($opens(),'The linked monitored domain page opens');
// A live holding of the same name takes precedence, so the watch page would not open and the departed page does not link it.
$live=$d->fresh()->replicate();$live->status='active';$live->renewal_policy=[];$live->save();
expect(!$opens(),'A same-name live holding hides the watch page');
same(0,count($monitoring()),'The departed page does not link a watch page that would not open');
$live->delete();
same(1,count($monitoring()),'The link comes back once no same-name live holding remains');
DB::transaction(fn()=>$watch->refresh()->update(['state'=>'retired','revision'=>$watch->revision+1]));
same(0,count($monitoring()),'A retired watch is no longer presented as running');
DB::transaction(fn()=>$watch->refresh()->update(['state'=>'paused','revision'=>$watch->revision+1]));
same(0,count($monitoring()),'A paused watch is not presented as running');
same('active',array_slice($d->fresh()->renewal_policy['staff_holdings'],-1)[0]['watch']['state'],'Current status reads preserve the saved handoff outcome');
echo "The departed page describes only a currently running owner watch.\n";
$nativeSuccessful=true;
