<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/identity-inventory.php';
$inventory=json_decode(file_get_contents(__DIR__.'/fixtures/native-identity-inventory.json'),true,512,JSON_THROW_ON_ERROR);
same('1.5.9',$inventory['version'],'Inventory must name its audited native version');
same($marker['commit'],$inventory['commit'],'Inventory pins the runner fixture commit');
$inventory['files']=json_decode(file_get_contents(__DIR__.'/fixtures/'.$inventory['hash_manifest']),true,512,JSON_THROW_ON_ERROR);
$problems=identityInventory(base_path(),$inventory);
expect($problems===[],"Re-review native identity paths before upgrade:\n".implode("\n",$problems));
foreach($inventory['paths'] as $path=>$disposition){
    expect(isset($inventory['files'][$path]),'Write path must have a pinned hash: '.$path);
    expect(str_starts_with($disposition,'models:')||str_starts_with($disposition,'outside:')||str_starts_with($disposition,'no identity write:'),'Explicit disposition: '.$path);
}
// The tripwire must identify changed, added and removed files, including a previously unknown writer.
$path='app/Models/Order.php';$original=file_get_contents(base_path($path));
try {
    file_put_contents(base_path($path),$original."\n// synthetic upgrade\n");
    expect(in_array('Changed native path: '.$path,identityInventory(base_path(),$inventory),true),'Changed path named');
    unlink(base_path($path));
    expect(in_array('Missing native path: '.$path,identityInventory(base_path(),$inventory),true),'Removed path named');
} finally {file_put_contents(base_path($path),$original);}
$added='app/Services/SyntheticIdentityWriter.php';
try {
    file_put_contents(base_path($added),'<?php // synthetic new writer');
    expect(in_array('Added native path: '.$added,identityInventory(base_path(),$inventory),true),'Added path named');
} finally {unlink(base_path($added));}
same([],identityInventory(base_path(),$inventory),'Fixture restored');
echo 'Native identity upgrade tripwire: '.count($inventory['files'])." hashes, explicit path dispositions and change/add/remove checks passed.\n";
$nativeSuccessful=true;
