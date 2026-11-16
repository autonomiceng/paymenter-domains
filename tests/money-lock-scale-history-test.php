<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// One settled target per path, before and after 10 unrelated invoices for the same owner.
$moneyScaleCells = []; $moneyScaleHistory = true;
require __DIR__.'/Support/money-lock-scale.php';
