<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The panel kit page on a seeded demo Enom account with its reminder schedule; synthetic data only.
declare(strict_types=1);
require_once dirname(__DIR__).'/native-app.php';
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/accounts.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PanelKit;
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema, ReminderSchedules, ReminderSchema};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;

/** The kit's account server id. A host skin (DOMAINS_PANEL_SETUP) is set up before the page is built. */
function panelKitFixture(): int
{
    config(['domains.time_zone' => 'America/Los_Angeles']);
    staffPanel();FoundationSchema::install();ReminderSchema::install();
    $account=fixtureAccount(1,'enom',DemoData::ACCOUNTS['enom']);
    ReminderSchedules::enroll($account['server_id']);
    $panel=Filament::getPanel('admin');$panel->pages([PanelKit::class]);
    Route::name('filament.admin.')->prefix($panel->getPath())->group(static function()use($panel){PanelKit::registerRoutes($panel);});
    app('router')->getRoutes()->refreshNameLookups();app('router')->getRoutes()->refreshActionLookups();
    return $account['server_id'];
}
