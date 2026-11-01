<?php
// Native locking, durable engine, controller ownership and real Blade cannot be exercised by a protocol unit test.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\{ManagementForms,ManagementProgress,OwnedDomains,OwnerPorts};
[$d,$driver]=$holding(placeholderComFixtureName('pending-control-placeholder.com'));
$op=$submit($d,$driver,'lock',['locked'=>false]);
same($op->id,$submit($d,$driver,'lock',['locked'=>false])->id,'A new nonce merges the same unfinished desired state');
$latest=$submit($d,$driver,'lock',['locked'=>true]);
same('management_retired',$op->fresh()->safe_code,'A different unsent choice retires the older request');
same('rejected',$management->run($op->id,$driver)->state,'Retired delivery never sends');same([],$state->calls,'No old send');
same('succeeded',$management->run($latest->id,$driver)->state,'The last unsent choice wins');
// Existing deployments can already contain two unsent choices from before per-setting merging.
Cache::flush();$older=$submit($d,$driver,'lock',['locked'=>false]);$legacy=$older->replicate();
$legacy->fill(['operation_key'=>'manage:'.$d->id.':'.$key(),'payload'=>['locked'=>true],
    'policy'=>['management'=>[...$older->policy['management'],'input'=>['locked'=>true]]]]);$legacy->save();
same($legacy->id,$submit($d,$driver,'lock',['locked'=>true])->id,'A fresh nonce merges the latest legacy choice');
same('management_retired',$older->fresh()->safe_code,'Merging also retires older unsent legacy choices');
$before=count($state->calls);same('rejected',$management->run($older->id,$driver)->state,'Legacy delivery cannot reverse the newer choice');
same($before,count($state->calls),'Retired legacy delivery makes no calls');
same('succeeded',$management->run($legacy->id,$driver)->state,'The retained choice confirms');
Cache::flush();$op=$submit($d,$driver,'lock',['locked'=>false]);$state->fault='SetRegLock';
same('uncertain',$management->run($op->id,$driver)->state,'Sealed unclear reply remains uncertain');
same($op->id,$submit($d,$driver,'lock',['locked'=>true])->id,'A different desired state returns the unfinished sealed request');
same(1,count(array_filter($state->calls,fn($call)=>$call[0]==='SetRegLock'&&$call[1]['UnlockRegistrar']===1)),'No repeated unlock');
$actor=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,'admin.domains.manage']);
same($op->id,$management->request($actor,$d->id,'lock',['locked'=>false],$key(),$driver)->id,'Staff and customer share the existing request');
$ports=new OwnerPorts(fn($b)=>$driver);$owners=new OwnedDomains($clock,$ports,$management);$controller=new OwnedDomainController($owners);
storefrontViews();$request=Request::create('/domains/'.$d->id);$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('request')->session());
$response=$controller->status($request,$d->id);$rows=$response->getData(true);
same($op->id,$rows['lock']['id'],'Status returns the durable operation');expect($rows['lock']['pending'],'Status remains pending');
expect(!str_contains($response->getContent(),'operation_key')&&!str_contains($response->getContent(),'payload'),'Status has no private operation data');
app()->instance(OwnerPorts::class,$ports);app()->instance(OwnedDomains::class,$owners);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\DomainManagement::class,$management);
$before=$state->calls;$controller->status($request,$d->id);same($before,$state->calls,'Polling never calls the registrar');
$html=$controller->show($request,$d->id)->render();
expect(str_contains($html,'data-management-pending="lock"'),'Reload keeps the pending control');
expect(!str_contains($html,'id="form-lock"'),'The unfinished setting has no second change control');
$foreign=Request::create('/');$foreign->setUserResolver(fn()=>commerceUser());
try{$controller->status($foreign,$d->id);expect(false,'Foreign status must fail');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
$old=$op->created_at;$op->update(['created_at'=>$old->copy()->subSeconds(31)]);
expect(ManagementProgress::for($d)['lock']['slow'],'Slow status survives reload');
$state->fault='';$state->locked=false;same('succeeded',$management->reconcile($op->id,$driver)->state,'Readback completes the original operation');
expect(!ManagementProgress::for($d)['lock']['pending'],'Completion stops polling');
$html=$controller->show($request,$d->id)->render();expect(str_contains($html,'id="form-lock"'),'Completion restores the action');
// A local failed attempt may retry safely, while a lost reply may not send again.
Cache::flush();$state->fault='refused';$op=$submit($d,$driver,'lock',['locked'=>true]);same('rejected',$management->run($op->id,$driver)->state,'A provider refusal finishes');
expect(!ManagementProgress::for($d)['lock']['pending'],'Definite failure stops polling');
$state->fault='';Cache::flush();$retry=$submit($d,$driver,'lock',['locked'=>true]);expect($retry->id!==$op->id,'Definite failure permits an explicit retry');
same('succeeded',$management->run($retry->id,$driver)->state,'Retry confirms');
// Staff and customer controls share the same stored operation, including slow and finished states.
Cache::flush();$pending=$submit($d,$driver,'lock',['locked'=>false]);
staffPanel();$staffPage=Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id]);
expect(str_contains($staffPage->html(),'wire:poll.2s'),'Staff pending controls poll every two seconds');
$before=$state->calls;$staffPage->call('pollManagement');same($before,$state->calls,'Staff polling reads stored state only');
$pending->update(['created_at'=>$pending->created_at->copy()->subSeconds(31)]);
$staffPage->call('pollManagement');expect(str_contains($staffPage->html(),'wire:poll.10s'),'Staff slow controls poll every ten seconds');
same('succeeded',$management->run($pending->id,$driver)->state,'Staff sees the same confirmed result');
$staffPage->call('pollManagement');expect(!str_contains($staffPage->html(),'wire:poll.'),'Staff completion stops polling');
// Forms register work after commit and after the response, without queue-worker availability.
Cache::flush();$forms=new ManagementForms($management,$ports);$before=count($state->calls);
$forms->submit($user,$d->fresh(),'lock',['locked'=>'1'],$key());
same($before,count($state->calls),'Saving performs no provider I/O');
app()->terminate();same('succeeded',$d->operations()->where('action','lock')->orderByDesc('id')->first()->state,'After-response callback starts the saved operation');
echo "Management pending controls, duplicate requests, local status and immediate start passed.\n";$nativeSuccessful=true;
