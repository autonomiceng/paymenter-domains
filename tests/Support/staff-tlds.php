<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native fixture registration only.
declare(strict_types=1);
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/commerce.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
function tldPanel(): void
{
    staffPanel();$panel=Filament::getCurrentPanel();$panel->resources([TldResource::class])->pages([RegistrarSetup::class]);
    Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())->group(function()use($panel){
        Route::middleware($panel->getAuthMiddleware())->group(function()use($panel){TldResource::registerRoutes($panel);RegistrarSetup::registerRoutes($panel);});
    });
    app('router')->getRoutes()->refreshNameLookups();app('router')->getRoutes()->refreshActionLookups();
}
