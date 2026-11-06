<?php
// Native NameSilo transfer evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
$unresolved(['late', 'credited', 'unknowncharge', 'paged', 'nohistory', 'lag']);
echo "NameSilo unresolved transfer evidence passed\n";$nativeSuccessful=true;
