<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$capturedFaults=['','open','failed','wrong-id','wrong-name','wrong-action','wrong-years','wrong-charge'];
require __DIR__.'/Support/reconciliation-captured.php';
$nativeSuccessful=true;echo "Native reconciliation scenario passed.\n";
