<?php
// Native owner DTOs, controllers and Blade need the installed fixture and its current catalogue ports.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/owned.php';require_once __DIR__.'/reserved-fixtures.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
$controller=new OwnedDomainController($owners);$session=app('request')->session();
$request=static function($actor)use($session){$r=Request::create('/domains','GET');$r->setUserResolver(fn()=>$actor);$r->setLaravelSession($session);return $r;};
// Restore the isolated native layout after each arranged checkout, as the original page matrix did.
$createOwnedPageTransfer=$newTransfer;
$newTransfer=static function(...$args)use($createOwnedPageTransfer){$operation=$createOwnedPageTransfer(...$args);storefrontViews();return $operation;};
