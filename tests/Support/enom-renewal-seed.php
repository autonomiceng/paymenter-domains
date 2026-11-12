<?php
// One committed native renewal holding seed; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$renewalGroup='seed';
$renewalCases=['success','calendar','cap','partial','anchor','overrun','auto','grace','uncertain','replacement','race','issuance'];
require __DIR__.'/enom-renewal-holders.php';
file_put_contents($receipt.'.renewal-holders',json_encode(['holders'=>array_map(static fn($domain)=>$domain->id,$holders),'at'=>$clock->now()->format(DATE_ATOM)],JSON_THROW_ON_ERROR));
$nativeSuccessful=true;
echo "Committed native renewal holdings seeded once.\n";
