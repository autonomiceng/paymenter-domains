<?php
// Synthetic Enom registration and correction cases; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$enomModes = ['clean', 'defaultdns', 'lost', 'mismatch', 'authority', 'finishfailure'];
$runEnomRegistrationTail = true;
require __DIR__.'/Support/enom-registration-suite.php';
