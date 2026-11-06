<?php
// Missing, unexpected and repeated scenario protection; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/scenario-inventory.php';
$expected=['before','due','after'];
foreach($expected as $drop){
    $inventory=new NativeScenarioInventory($expected);
    foreach($expected as $scenario){if($scenario!==$drop){$inventory->completed($scenario);}}
    try{$inventory->finish();expect(false,'Dropping '.$drop.' must fail');}
    catch(RuntimeException $error){same('Missing required scenarios: '.$drop,$error->getMessage(),'Independent inventory detects exactly the omitted scenario');}
}
$inventory=new NativeScenarioInventory($expected);
foreach($expected as $scenario){$inventory->completed($scenario);}
$inventory->finish();
foreach(['due','unexpected'] as $scenario){
    try{$inventory->completed($scenario);expect(false,'Repeated or unexpected scenario must fail');}
    catch(RuntimeException $error){same('Unexpected or repeated scenario: '.$scenario,$error->getMessage(),'Inventory does not accept replacement or duplicate cases');}
}
echo "Independent scenario inventories detect omissions, replacements and duplicates\n";
