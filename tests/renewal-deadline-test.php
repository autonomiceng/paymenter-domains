<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic transport only.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
[$op,$d,$binding] = $makeRenewal(placeholderComFixtureName('renewal-deadline-placeholder.com'));
$setNow = static fn (DateTimeImmutable $at) => $clock->instant = $at;
(require __DIR__.'/Support/renewal-deadline-cases.php')($op,$d,$binding,$compose,$clock,$state,$setNow,$nativeMoney);
$nativeSuccessful = true;
echo "Checkout renewal entry deadlines passed\n";
