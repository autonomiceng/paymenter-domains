<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Services\{FreshInstallation, Installation};

// Uploading over the 751bb8139 package runs its Domains class and lifecycle in the same process after the new
// files are in place, and Landing calls the same entry points. Each member they call must stay callable.
$members = [
    FreshInstallation::class => [
        'static' => ['requireCompatible' => 'void', 'install' => 'void', 'uninstalled' => 'bool', 'recovering' => 'bool'],
        'instance' => [],
        'constants' => ['CONTRACT', 'MESSAGE', 'STAFF'],
    ],
    Installation::class => [
        'static' => ['legacy' => 'void', 'tables' => 'array', 'expectedTables' => 'array'],
        'instance' => ['install' => 'void', 'verify' => 'void', 'verifyForOrders' => 'void'],
        'constants' => ['BASELINE', 'SCHEMAS', 'VERIFIED_KEY'],
    ],
];
foreach ($members as $class => $expected) {
    $reflection = new ReflectionClass($class);
    foreach (['static' => true, 'instance' => false] as $kind => $static) {
        foreach ($expected[$kind] as $name => $return) {
            expect($reflection->hasMethod($name), $class.'::'.$name.' is kept');
            $method = $reflection->getMethod($name);
            expect($method->isPublic() && $method->isStatic() === $static, $class.'::'.$name.' keeps its call form');
            same(0, $method->getNumberOfRequiredParameters(), $class.'::'.$name.' needs no arguments');
            same($return, (string) $method->getReturnType(), $class.'::'.$name.' keeps its return type');
        }
    }
    foreach ($expected['constants'] as $constant) {
        expect($reflection->hasConstant($constant), $class.'::'.$constant.' is kept');
    }
}
echo "installation entry points kept\n";
