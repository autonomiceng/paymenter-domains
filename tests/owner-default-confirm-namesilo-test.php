<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The same site default confirmation for a NameSilo holding.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
require __DIR__.'/Support/owner-pages.php';
[$d]=$holding('default-confirm');
require __DIR__.'/Support/owner-default-confirm.php';
$freshFill();
$changedDefault();
$nativeSuccessful=true;echo "Current site default requires a fresh explicit confirmation for NameSilo.\n";
