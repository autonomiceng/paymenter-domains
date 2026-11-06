<?php
// Guard-level upgrade probe; no alternate Paymenter installation is claimed.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Illuminate\Support\Facades {
    final class DB
    {
        public static function connection(): never { throw new \RuntimeException('Database access before mismatched native pin refusal'); }
    }
}
namespace {
    require __DIR__.'/bootstrap.php';
    require __DIR__.'/Support/renewal-initialized.php';
    // Stand in for the native harness after it has verified a legitimately upgraded dependency.
    define('DOMAINS_TEST_NATIVE_COMMIT',str_repeat('a',40));
    $snapshot=json_decode(file_get_contents(__DIR__.'/fixtures/renewal-initialized.json'),true,512,JSON_THROW_ON_ERROR);
    expect(DOMAINS_TEST_NATIVE_COMMIT!==$snapshot['paymenter_commit'],'Probe uses a different verified native pin');
    $failure=null;
    try { initializedRenewalFixture(); } catch(\RuntimeException $error) { $failure=$error->getMessage(); }
    same('Prepared setup uses the native source pin',$failure,'Different verified native pin must refuse the snapshot before database access; got: '.($failure??'no refusal'));
    echo "Changed verified native pin refuses the old renewal snapshot before database access\n";
}
