<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Split the existing native matrix to retain every refusal within the unchanged per-file budget.
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
$unresolved(['inactive','auto','privacy','short','owner','nocharge','duplicate']);
echo "NameSilo transfer refusal matrix passed.\n"; $nativeSuccessful=true;
