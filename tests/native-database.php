<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/core-database.php';
$booted = (new ReflectionProperty(Illuminate\Database\Eloquent\Model::class, 'booted'))->getValue();
exportCoreDatabase(Illuminate\Support\Facades\DB::connection()->getPdo(), (string)getenv('PAYMENTER_TEST_DATABASE_EXPORT'), array_keys($booted));
$nativeSuccessful = true;
