<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline real-page capture with synthetic records.
declare(strict_types=1);
// Look evidence comes only from tools/bin/skin-render, which sets the host skin and its boot marker. Refuse before any
// setup, so a direct run never writes stock-theme pages.
if((string)getenv('DOMAINS_PANEL_SETUP')===''||(string)getenv('SKIN_RENDER_MARKER')===''){throw new RuntimeException('Run this fixture through tools/bin/skin-render.');}
$out=$argv[1]??'';
// A monitored domain whose hosting service ended (the regression's own fixture).
require dirname(__DIR__).'/watch-stopped-restart-link-test.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
$nativeSuccessful=false;expect(is_dir($out),'Render output exists');
config(['domains.time_zone'=>'America/Los_Angeles']);
Illuminate\Support\Facades\Auth::setUser($manager);panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
writePanelPage($out.'/staff-watch-stopped.html',Livewire\Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]));
writePanelPage($out.'/staff-watches.html',Livewire\Livewire::actingAs($manager)->test(ListWatches::class));
$nativeSuccessful=true;echo "Stopped monitored domain pages rendered offline.\n";
