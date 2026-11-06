<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
$capturedFaults=['authority'];
require __DIR__.'/Support/reconciliation-captured.php';
$nativeSuccessful=true;echo "Native captured authority refusal passed.\n";
