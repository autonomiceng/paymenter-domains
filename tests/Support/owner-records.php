<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native composition; no network.
declare(strict_types=1);
require __DIR__.'/management.php';
require __DIR__.'/owner-pages.php';
require_once __DIR__.'/raw-input.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController,OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerPorts,OwnerSetup,TransferInputs,Refunds};
$ports=new OwnerPorts(function($b)use(&$driver){return $driver;});
$owners=new OwnedDomains($clock,$ports,$management);
$controller=new OwnerActionController($owners,new OwnerSetup($clock,$ports),new TransferInputs($clock,new Refunds($clock)),$management,$ports);
$session=app('request')->session();$session->put('_token','records-csrf');
$post=static function(string $action,array $data=[],bool $json=false)use(&$d,$user,$session):Request {
    $url='/domains/'.$d->id.'/manage/'.$action;$data=['confirmed'=>'1',...$data];
    $keyRequest=Request::create($url,'POST');$keyRequest->setLaravelSession($session);
    $data['_request_key']=FormKeys::issue($keyRequest,'owner:'.$d->id.':'.$action);
    $raw=$json?json_encode($data,JSON_THROW_ON_ERROR):http_build_query($data,'','&',PHP_QUERY_RFC1738);
    $r=Request::create($url,'POST',$json?[]:$data,[],[],['CONTENT_TYPE'=>$json?'application/json':'application/x-www-form-urlencoded'],$raw);
    $r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
    if(in_array($action,['dns','srv'],true)){nativeNormalizeInput($r);}return $r;
};
$page=static function()use(&$d,$owners,$post):string { return (new OwnedDomainController($owners))->show($post('dns'),$d->id)->render(); };
$ds=['key_tag'=>'27578','algorithm'=>'13','digest_type'=>'2','digest'=>str_repeat('A',64)];
