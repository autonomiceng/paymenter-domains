<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-customer-fixture.php';
adoptionCustomerScenario('replacements');
$nativeSuccessful=true;echo "Adopted native replacement payment, refund and retry passed.\n";
