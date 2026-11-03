<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Services\Installation;

// Native transactions and retained projections are required to prove a quiet conversion window;
// this fixture has synthetic accounts and no installed provider transport.
(new Installation)->install();
extract(commerceFixture());
$setting = Setting::create(['key' => 'domains_offer_upgrade', 'settingable_type' => null,
    'settingable_id' => null, 'type' => 'string', 'encrypted' => true,
    'value' => json_encode(['state' => 'paused', 'generation' => 1])]);
$refused = false;
try { $native->publish($tlds[0]->id, $now); }
catch (DomainException $error) { $refused = str_contains($error->getMessage(), 'Catalogue upgrade'); }
same(true, $refused, 'Quiet conversion window refuses legacy native publication');
same(1, UpgradeGate::generation(), 'Quiet window generation is durable across service instances');
$gate = new UpgradeGate;
$evidence = $gate->capture();
same(false, $gate->check($evidence)['compatible'], 'Unconverted retained offers cannot pass the gate');
$gate->resume();
$native->publish($tlds[0]->id, $now);
same(2, UpgradeGate::generation(), 'Rollback resumes legacy writers without dropping staging storage');
$nativeSuccessful = true;
echo "Offer upgrade gate checks passed.\n";
