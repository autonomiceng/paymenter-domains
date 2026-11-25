<?php
// Completed NameSilo no-ID registration with saved registration history; MIT, Copyright (c) 2026 Autonomic Engineering.
// Setup only: reconciliation-namesilo-test.php owns the assertions on this completion.
declare(strict_types=1);
require __DIR__.'/namesilo-no-id-sealed.php';
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances;
HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 600, 86400, 'namesilo_registration_v1'));
$clock->instant = $clock->now()->modify('+301 seconds');
$result = $reconcile->run($op->id);
