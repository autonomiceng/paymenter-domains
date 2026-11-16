<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Small regression pass: N=5/15 at C=1, plus N=5 at C=2, over all four paths.
// Optional research matrix (N=5/20/50 x C=1/2/4), outside the default suite:
// DOMAINS_TEST_MONEY_SCALE_FULL_MATRIX=1 python3 scripts/test.py --jobs 1 --only tests/money-lock-scale-test.php
$moneyScaleCells = [[5,1],[15,1],[5,2]]; $moneyScaleHistory = false;
if (getenv('DOMAINS_TEST_MONEY_SCALE_FULL_MATRIX') === '1') {
    $moneyScaleCells = [];
    foreach ([5,20,50] as $n) { foreach ([1,2,4] as $customers) { $moneyScaleCells[] = [$n,$customers]; } }
}
require __DIR__.'/Support/money-lock-scale.php';
