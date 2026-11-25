<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-customer-fixture.php';
// Display and the native replacement payment, refund and retry share one adopted customer.
adoptionCustomerScenario('display','replacements');
$nativeSuccessful=true;echo "Adopted customer display, original history and native replacements passed.\n";
