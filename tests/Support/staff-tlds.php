<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native fixture registration only.
declare(strict_types=1);
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/commerce.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
function tldPanel(): void
{
    \Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
    // The Registrars list reads balances; bind the clock as Services/Runtime.php does when nothing else did.
    if(!app()->bound(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)){app()->singleton(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,\Paymenter\Extensions\Servers\Domains\Data\SystemClock::class);}
    staffPanel();$panel=Filament::getCurrentPanel();$panel->resources([TldResource::class])->pages([RegistrarSetup::class,RegistrarAccount::class]);
    Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())->group(function()use($panel){
        Route::middleware($panel->getAuthMiddleware())->group(function()use($panel){TldResource::registerRoutes($panel);RegistrarSetup::registerRoutes($panel);RegistrarAccount::registerRoutes($panel);});
    });
    app('router')->getRoutes()->refreshNameLookups();app('router')->getRoutes()->refreshActionLookups();
}

function tldRefusal(callable $call,string $message): void
{
    try{$call();expect(false,'Expected exact settings refusal: '.$message);}
    catch(DomainException $e){same($message,$e->getMessage(),'Exact settings refusal: expected '.$message.'; got '.$e->getMessage());}
}
