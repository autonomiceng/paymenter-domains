<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/seller-switch-lifecycle.php';
$sellerLifecycle(1, 'transfer');
echo "Namesilo transfer seller-switch lifecycle passed\n";
$nativeSuccessful = true;
