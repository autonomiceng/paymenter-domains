<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline real-page capture with synthetic records.
declare(strict_types=1);
$out=$argv[1]??'';
// A confirmed departure whose email failed to build, so the departed page keeps the open email alert.
require dirname(__DIR__).'/departure-email-failure-test.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
$nativeSuccessful=false;expect(is_dir($out),'Render output exists');
config(['domains.time_zone'=>'America/Los_Angeles']);
Auth::setUser($staff);panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
writePanelPage($out.'/staff-departed-alerts.html',Livewire\Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]));
$nativeSuccessful=true;echo "Staff departed page with an open alert rendered offline.\n";
