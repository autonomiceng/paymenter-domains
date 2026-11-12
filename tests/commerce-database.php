<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/core-database.php';
require_once __DIR__.'/Support/FakeClock.php';
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,
    new Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable('2026-10-18T12:00:00Z')));
Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
(new Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
$booted=(new ReflectionProperty(Illuminate\Database\Eloquent\Model::class,'booted'))->getValue();
exportCoreDatabase(Illuminate\Support\Facades\DB::connection()->getPdo(),(string)getenv('PAYMENTER_TEST_COMMERCE_EXPORT'),array_keys($booted));
$nativeSuccessful=true;
