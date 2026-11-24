<?php
// Native NameSilo transfer safety; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
$unresolved([
    'retention', 'baseline', 'nullexpiry', 'pending', 'expiration', 'badexpiry',
    'reversed', 'race', 'needsowned', 'needscharge', 'sealedprivacy', 'rejectowned', 'rejectcharge', 'unknown',
]);
echo "NameSilo transfer refusal evidence passed\n";
$nativeSuccessful = true;
