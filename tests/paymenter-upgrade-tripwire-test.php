<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/upgrade-tripwire.php';
// Optional arguments inspect a copied candidate directly, independently of the pristine fixture guard.
$root = rtrim($argv[1] ?? (string) getenv('PAYMENTER_TEST_FIXTURE'), '/');
expect($root !== '' && is_dir($root), 'A Paymenter checkout with vendor is required.');
$version = $argv[2] ?? '1.5.9';
$changes = paymenterUpgradeChanges($root, $version);
if ($changes) { fwrite(STDERR, implode("\n", $changes)."\n"); exit(1); }
// Small synthetic trees prove changed/missing/added diagnostics without writing the dependency.
$temp = sys_get_temp_dir().'/domains-tripwire-'.bin2hex(random_bytes(8));
$relative = 'app/Services/Invoice/Existing.php'; $added = 'app/Services/Invoice/NewWriter.php';
mkdir($temp.'/app/Services/Invoice', 0700, true);
$contract = ['consumers'=>['Domains/Services/InvoiceFunding.php'], 'review'=>'Re-review payment timing.', 'files'=>[$relative=>hash('sha256', '<?php original')]];
$synthetic = ['test'=>['contracts'=>['funding'=>$contract], 'watch'=>['app/Services/Invoice'=>'funding']]];
try {
    file_put_contents($temp.'/'.$relative, '<?php original');
    same([], paymenterUpgradeChanges($temp, 'test', $synthetic), 'Exact baseline passes');
    file_put_contents($temp.'/'.$relative, '<?php changed');
    same(['changed '.$relative.' -> Domains/Services/InvoiceFunding.php -> Re-review payment timing.'], paymenterUpgradeChanges($temp, 'test', $synthetic), 'Changed member names its dependent and review');
    unlink($temp.'/'.$relative); file_put_contents($temp.'/'.$added, '<?php');
    same(['added '.$added.' -> Domains/Services/InvoiceFunding.php -> Re-review payment timing.', 'missing '.$relative.' -> Domains/Services/InvoiceFunding.php -> Re-review payment timing.'], paymenterUpgradeChanges($temp, 'test', $synthetic), 'Removed and new writers both require review');
    expect(str_contains(paymenterUpgradeChanges($temp, 'unknown', $synthetic)[0], 'Qualify this exact release'), 'Unlisted release has no implicit baseline');
} finally {
    foreach ([$relative, $added] as $file) { if (is_file($temp.'/'.$file)) { unlink($temp.'/'.$file); } }
    rmdir($temp.'/app/Services/Invoice'); rmdir($temp.'/app/Services'); rmdir($temp.'/app'); rmdir($temp);
}
echo "Paymenter upgrade tripwires passed for ".$version.".\n";
