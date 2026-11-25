<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline real-page capture with synthetic records.
declare(strict_types=1);
$out=$argv[1]??'';
// A confirmed departure with a paid renewal returned as account credit (the regression's own fixture).
require dirname(__DIR__).'/departure-departed.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains,ViewDomain};
$nativeSuccessful=false;expect(is_dir($out),'Render output exists');
config(['domains.time_zone'=>'America/Los_Angeles']);
Auth::setUser($staff);panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
writePanelPage($out.'/staff-departed.html',Livewire\Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]));
writePanelPage($out.'/staff-departed-history.html',Livewire\Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->set('activeRelationManager','0'));
$nativeSuccessful=true;echo "Staff departed pages rendered offline.\n";
