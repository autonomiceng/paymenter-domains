<?php
// Single owner save through real presentation and controller. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
require __DIR__.'/Support/owner-pages.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController,OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerPorts,OwnerSetup,TransferInputs,Refunds};
[$d,$driver,$original]=$holding('single-form');
$ports=new OwnerPorts(fn($b)=>$driver);$owners=new OwnedDomains($clock,$ports,$management);
$dto=$owners->present($d);same(['contacts'],array_keys($dto->management['contacts']['actions']),'The owner gets one contact-save action');
$get=Request::create('/domains/'.$d->id);$get->setUserResolver(fn()=>$user);$get->setLaravelSession(app('request')->session());
$html=(new OwnedDomainController($owners))->show($get,$d->id)->render();
expect(!str_contains($html,'Send for approval')&&!str_contains($html,'Use the approved details')&&!str_contains($html,'Step 1 of 2'),'The editor has no manual profile stage');
same(1,storefrontDom($html)->query('//form[contains(@action,"/manage/contacts")]')->length,'One ordinary contacts form');
$input=['role'=>'all','contact'=>$original->payload['contacts']['registrant']];
$session=app('request')->session();$session->put('_token','synthetic-csrf');
$r=Request::create('/domains/'.$d->id.'/manage/contacts','POST',$input);
$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
$r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':contacts'));
$controller=new OwnerActionController($owners,new OwnerSetup($clock,$ports),new TransferInputs($clock,new Refunds($clock)),$management,$ports);
$controller->manage($r,$d->id,'contacts');
$op=$d->operations()->where('action','contacts')->sole();
same('queued',$op->state,'One save queues the contact change');same([],$ms->calls,'The POST performs no registrar calls');
$management->run($op->id,$driver);Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Queued creation and association finish without another owner action');
same(['contacts'],array_keys($owners->present($d->fresh())->management['contacts']['actions']),'Completed editor still offers the ordinary save');
$nativeSuccessful=true;echo "Single NameSilo contact editor passed.\n";
