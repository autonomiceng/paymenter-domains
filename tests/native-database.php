<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/core-database.php';
exportCoreDatabase(Illuminate\Support\Facades\DB::connection()->getPdo(), (string)getenv('PAYMENTER_TEST_DATABASE_EXPORT'));
$nativeSuccessful = true;
