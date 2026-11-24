<?php
// Native NameSilo transfer evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
$unresolved([
    'owner', 'nocharge', 'duplicate',
    'late', 'credited', 'unknowncharge', 'paged', 'nohistory', 'lag',
    'inactive', 'auto', 'privacy', 'short',
]);
echo "NameSilo transfer refusal evidence passed\n";
$nativeSuccessful = true;
