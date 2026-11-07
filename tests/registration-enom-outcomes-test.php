<?php
// Synthetic Enom unresolved outcomes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$enomModes = ['unknown', 'pending', 'processing', 'queued', '1300', 'missingid', 'invalidid', 'missingexpiry', 'invalidexpiry'];
$runEnomRegistrationTail = false;
require __DIR__.'/Support/enom-registration-suite.php';
