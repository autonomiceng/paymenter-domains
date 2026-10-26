<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$root=dirname(__DIR__);
$watch=file_get_contents($root.'/docs/design/watch-schedule.md');
expect(!str_contains($watch,'reports disabled, invalid queue'),'Queue configuration is not a monitoring-page notice');
expect(str_contains($watch,'A queue that can’t run monitoring shows as "Queue timeout too short" in the Background worker row, under System checks on each registrar account page.'),'Queue documentation points to its account check');
$adoption=file_get_contents($root.'/docs/testing-coverage/adoption-focused.md');
$identity=file_get_contents($root.'/docs/testing-coverage/native-identity-focused.md');
expect(str_starts_with($adoption,"# Focused checks for adoption and registrars\n"),'Adoption heading names its subjects clearly');
expect(str_starts_with($identity,"# Focused checks for native identity and managed public enrollment\n"),'Native identity heading includes enrollment');
expect(str_contains($identity,'[focused checks for adoption and registrars](adoption-focused.md)'),'Coverage link text matches its destination heading');
echo "UI documentation matches the current controls.\n";
