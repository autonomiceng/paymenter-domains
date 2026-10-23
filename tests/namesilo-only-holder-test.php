<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/paired-driver.php';
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\HolderPricing;
foreach (['register', 'transfer'] as $action) {
    $op = $scenarioNew(1, $action, 'only-namesilo-'.$action.'.com');
    $scenarioCompose(1, $action); $state->calls = [];
    scenarioRetained($op->domain, static fn () => scenarioSwitch($sellers, $clock, $tlds[0]->server_id));
    $money = scenarioMoney(); $result = $engine->execute($op->id);
    scenarioExecution($result); same($money, scenarioMoney(), 'Initial non-selling dispatch preserves native finance');
    same($action === 'register' ? 'pending' : 'uncertain', $result->state, 'NameSilo-only holding dispatches without Enom holdings');
    same([$action === 'register' ? 'registerDomain' : 'transferDomain'], $scenarioSends(), 'Only the original NameSilo account sends');
    same('10.40', (new HolderPricing($prices))->renewal($result->domain, 'USD', $clock->now())->retail, 'Non-selling NameSilo renewal price remains readable');
}
same(['namesilo'], Domain::query()->distinct()->pluck('registrar_key')->all(), 'NameSilo-only population exercised');
same(2, Domain::count(), 'Both acquisition actions retain NameSilo holdings');
echo "NameSilo-only holder population with Enom selling passed\n"; $nativeSuccessful = true;
