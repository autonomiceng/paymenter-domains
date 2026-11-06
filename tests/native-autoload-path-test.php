<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Composer's optimized class map must resolve native application classes inside the disposable fixture.
require __DIR__.'/native-app.php';
foreach ([App\Observers\OrderObserver::class, App\Observers\InvoiceObserver::class, App\Models\Invoice::class] as $class) {
    $expected = $fixture.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    same($expected, (new ReflectionClass($class))->getFileName(), 'Native application class is fixture-local: '.$class);
}
$nativeSuccessful = true;
echo "Native optimized autoload paths remain fixture-local.\n";
