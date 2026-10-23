<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adopted-renewal.php';
use Paymenter\Extensions\Servers\Domains\Data\Environment;
extract(adoptedRenewalFixture(Environment::Test));
holdingRefused(fn () => $billing->invoice($domain->id, $owner), 'Consistent TEST binding and evidence never anchor renewal');
same(0, $domain->operations()->count(), 'TEST adoption has no financial obligation');
same([], $state->calls, 'TEST adoption refusal is local');
echo "Matching TEST adoption, account and original holding never grant renewal authority.\n";
$nativeSuccessful = true;
