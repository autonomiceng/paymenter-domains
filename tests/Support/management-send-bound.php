<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Offline native operation tests. All provider responses are local protocol fakes.
declare(strict_types=1);
require __DIR__.'/management.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement, ManagementEvidence, WriteFeatures};

$uncertain = static function (string $name) use ($holding, $submit, $management, $state): array {
    [$d, $driver] = $holding(placeholderComFixtureName($name.'-placeholder.com'));
    $state->apply = false;
    $op = $submit($d, $driver, 'lock', ['locked' => false]);
    same('uncertain', $management->run($op->id, $driver)->state, 'Unconfirmed write remains uncertain');
    same(1, $op->fresh()->attempt['management_observation_attempts'], 'Inline readback is reserved before I/O');
    return [$d, $driver, $op];
};
