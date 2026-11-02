<?php
// A sidebar must not run registrar forecasts. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
staffPanel();staffData();Auth::setUser(staffUser([Staff::VIEW]));
\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
$reads=[];DB::listen(function($query)use(&$reads):void{$reads[]=$query->sql;});
same(null,RegistrarSetup::getNavigationBadge(),'No Registrars chip without a cheap saved answer');
same([], $reads, 'Registrars does not load balances, accounts or a renewal forecast in the menu');
Auth::setUser(staffUser([]));
same(null,RegistrarSetup::getNavigationBadge(),'No Registrars chip without access');
$nativeSuccessful=true;echo "Registrars stays quiet without a cheap saved answer.\n";
