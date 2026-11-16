<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native suite discovery registers this shard; each runs in its own offline database.
$moneyScaleSizes = [5]; $moneyScaleOwners = [1, 2, 4];
$moneyScaleClosed = [false, true]; $moneyScaleHistory = true;
require __DIR__.'/Support/money-lock-scale.php';
