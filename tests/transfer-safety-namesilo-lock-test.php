<?php
// Synthetic NameSilo transfer evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
$transferSafetyStatuses=[2=>'Domain is Locked'];
require __DIR__.'/Support/transfer-safety-namesilo.php';
echo "NameSilo transfer status safety passed.\n";$nativeSuccessful=true;
