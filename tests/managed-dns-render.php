<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Renders the staff DNS monitoring setup list, whose rows open nothing, with a long customer; no public I/O.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';require_once __DIR__.'/Support/staff-panel.php';require __DIR__.'/Support/panel-render.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth, Route};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{RoutingProfileResource, WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ListManagedDns;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchSchema, StaffWatchWrites, WatchNoticeSchema, WatchScheduleSchema};
$out=renderOutput($argv[1]??null);
extract(managedFixture(false,false));$time->now=time();
WatchScheduleSchema::install();WatchNoticeSchema::install();StaffWatchSchema::install();staffPanel();
$panel=Filament::getCurrentPanel();$panel->resources([WatchResource::class,RoutingProfileResource::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel){WatchResource::registerRoutes($panel);RoutingProfileResource::registerRoutes($panel);});
app('router')->getRoutes()->refreshNameLookups();app('router')->getRoutes()->refreshActionLookups();
panelTheme($fixture);
// A real eligible holding whose account email is too long for the 120px customer column. A viewer has no row action.
$owner->update(['email'=>'maximiliana.featherstonehaugh-worthington@example.test']);
Auth::setUser(staffUser([Staff::VIEW,StaffWatchWrites::VIEW]));
writePanelPage($out.'/staff-managed-dns.html',Livewire::test(ListManagedDns::class)->assertCanSeeTableRecords([$d]));
$nativeSuccessful=true;echo "Staff DNS monitoring setup list rendered.\n";
