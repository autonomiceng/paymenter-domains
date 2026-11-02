<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/accounts.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeConversion;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, Installation};

// A real retained database is necessary: transaction boundaries, IDs and repeated upgrades
// are the converter's public persistence behavior, not provider or arithmetic mocks.
(new Installation)->install();
$key = fixtureAccount(1, 'enom', 'synthetic-conversion');
$tld = Tld::create([...$key, 'tld' => 'com', 'enabled' => false]);
$binding = CatalogueAcceptances::binding(Account::where('server_id', 1)->sole());
$result = (new UpgradeConversion)->batch($binding, 1);
same(1, $result['converted'], 'Conversion stages the retained offer instead of silently skipping it');
$nativeSuccessful = true;
echo "Offer conversion checks passed.\n";
