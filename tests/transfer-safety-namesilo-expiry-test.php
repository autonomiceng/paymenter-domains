<?php
// Native NameSilo transfer safety; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
$unresolved(['expiration', 'badexpiry']);
echo "NameSilo transfer safety evidence passed\n";$nativeSuccessful=true;
