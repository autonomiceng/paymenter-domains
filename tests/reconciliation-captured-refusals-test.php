<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
$capturedFaults = ['account', 'authority', 'wrong-expiry', 'multiple', 'owner', 'wrong-currency', 'term', 'window'];
require __DIR__.'/Support/reconciliation-captured.php';
$nativeSuccessful=true;echo "Native captured order refusals passed.\n";
