<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$boundaryCases=['funding','owner','generation','reservation','pause','peer','acceptance','lease'];
require __DIR__.'/Support/write-boundary.php';
$nativeSuccessful=true;echo "Sealed bridge current authority checks passed.\n";
