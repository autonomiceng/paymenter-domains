<?php
// Synthetic NameSilo profile registration cases; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$nameSiloModes = ['remove', 'stillon', 'badexpiry', 'rereadfail', 'lost', 'unknown', 'default', 'wrongcontact', 'authoritydrift', 'hyphenphone', 'wrongphone', 'missingphone'];
$runNameSiloProfileTail = false;
require __DIR__.'/Support/namesilo-registration-suite.php';
