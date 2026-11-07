<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
$capturedFaults=['account'];
require __DIR__.'/Support/reconciliation-captured.php';
$nativeSuccessful=true;echo "Native captured account refusal passed.\n";
