<?php
// Native discovery in a disposable application only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
function installedPanel(): void
{
    PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());
    $dir=base_path('extensions/Servers');if(!is_dir($dir)){mkdir($dir,0700,true);}
    if(!file_exists($dir.'/Domains')){symlink(dirname(__DIR__,2).'/Domains',$dir.'/Domains');}
    $panel=(new App\Providers\Filament\AdminPanelProvider(app()))->panel(Filament\Panel::make());
    app(Filament\PanelRegistry::class)->register($panel);
    require base_path('vendor/filament/filament/routes/web.php');
    \Paymenter\Extensions\Servers\Domains\Services\Runtime::secureNativeRoutes();
    app('router')->getRoutes()->refreshNameLookups();app('router')->getRoutes()->refreshActionLookups();
    Filament\Facades\Filament::setCurrentPanel($panel);
    Qirolab\Theme\Theme::set('default','default');
}
