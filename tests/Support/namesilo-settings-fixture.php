<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/namesilo-test-activation-test.php';$nativeSuccessful=false;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
// Fresh installation boots the database cache before these real settings actions.
Illuminate\Support\Facades\Schema::create('cache',static function($t):void {
    $t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');
});
staffPanel();$panel=Filament::getCurrentPanel();$pages=[RegistrarAccount::class,Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class,Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarHistory::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($pages,$panel){foreach($pages as $p){$p::registerRoutes($panel);}});
app('router')->getRoutes()->refreshNameLookups();
