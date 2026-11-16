<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native suite discovery registers this shard; each runs in its own offline database.
$moneyScaleSizes = [50]; $moneyScaleOwners = [2];
$moneyScaleClosed = [true]; $moneyScaleHistory = false;
require __DIR__.'/Support/money-lock-scale.php';
