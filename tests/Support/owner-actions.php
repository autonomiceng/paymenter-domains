<?php
// Synthetic native owner intent regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/owned.php';
require_once __DIR__.'/reserved-fixtures.php';
require_once __DIR__.'/raw-input.php';
$createTransfer=$newTransfer;
$newTransfer=static function (string $name, int $i=0, bool $paid=true, bool $code=true) use ($createTransfer) {
    return $createTransfer(placeholderComFixtureName($name),$i,$paid,$code);
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{OwnerSetup, DomainManagement};
$setup=new OwnerSetup($clock,$ports); $management=new DomainManagement($bindings,$clock);
$action=new OwnerActionController($owners,$setup,$inputs,$management,$ports);
$session=app('request')->session();$other=commerceUser();
$req=static function($d,string $intent,array $data=[], $actor=null)use($user,$session):Request {
    $r=Request::create('/domains/'.$d->id,'POST',$data,[],[],['CONTENT_TYPE'=>'application/x-www-form-urlencoded'],http_build_query($data,'','&',PHP_QUERY_RFC1738));
    if($intent==='code'){nativeNormalizeInput($r);}$r->setUserResolver(fn()=>$actor??$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':'.$intent));return $r;
};
$replay=static function($call):void {try{$call();expect(false,'Replay refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Replay needs a fresh intent');}};
