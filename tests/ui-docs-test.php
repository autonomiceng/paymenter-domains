<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$root=dirname(__DIR__);
$watch=file_get_contents($root.'/docs/design/watch-schedule.md');
expect(!str_contains($watch,'reports disabled, invalid queue'),'Queue configuration is not a monitoring-page notice');
expect(str_contains($watch,'A queue that can’t run monitoring shows as "Queue timeout too short" in the Background worker row, under System checks on each registrar account page.'),'Queue documentation points to its account check');
echo "UI documentation matches the current controls.\n";
