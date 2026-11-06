<?php
// Replayed setup keeps the installed schema/authority contract; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/renewal-initialized.php';
initializedRenewalFixture();
$before=Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::routeState();
(new Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
same($before,Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::routeState(),'Normal installer accepts the authentic prepared state without changing its authority');
same(0,Paymenter\Extensions\Servers\Domains\Models\Account::count(),'Prepared state contains no provider accounts');
same(0,Paymenter\Extensions\Servers\Domains\Models\Operation::count(),'Prepared state contains no paid or executable work');
echo "Authentic prepared native setup passes the normal installer\n";$nativeSuccessful=true;
