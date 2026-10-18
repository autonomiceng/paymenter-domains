<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native composition; no network.
declare(strict_types=1);
require __DIR__.'/management.php';
require __DIR__.'/owner-pages.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController,OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerPorts,OwnerSetup,TransferInputs,Refunds};
$ports=new OwnerPorts(function($b)use(&$driver){return $driver;});
$owners=new OwnedDomains($clock,$ports,$management);
$controller=new OwnerActionController($owners,new OwnerSetup($clock,$ports),new TransferInputs($clock,new Refunds($clock)),$management,$ports);
$session=app('request')->session();$session->put('_token','records-csrf');
$post=static function(string $action,array $data=[])use(&$d,$user,$session):Request {
    $r=Request::create('/domains/'.$d->id.'/manage/'.$action,'POST',['confirmed'=>'1',...$data]);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':'.$action));return $r;
};
$page=static function()use(&$d,$owners,$post):string { return (new OwnedDomainController($owners))->show($post('dns'),$d->id)->render(); };
$ds=['key_tag'=>'27578','algorithm'=>'13','digest_type'=>'2','digest'=>str_repeat('A',64)];
