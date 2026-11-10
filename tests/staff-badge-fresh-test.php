<?php
// The Domains menu badge and the Open alerts list say one number: opening the list refreshes the one-minute badge cache.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainQueries, NavigationCount, Staff};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
staffPanel(); staffData();
$staff=staffUser([Staff::VIEW]); Auth::setUser($staff);
// A badge counted a minute ago, before alerts cleared.
same('99',NavigationCount::read('domains',fn()=>99),'The menu caches its count for a minute');
same('99',DomainResource::getNavigationBadge(),'The cached badge is stale');
Livewire::actingAs($staff)->test(ListDomains::class);
$count=DomainQueries::openAlertCount(Domain::query());
same($count>0?(string)$count:null,DomainResource::getNavigationBadge(),'After opening the list the badge counts the same domains as Open alerts');
echo "Badge fresh passed.\n";
$nativeSuccessful=true;
