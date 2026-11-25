<?php
// Reviewed NameSilo TEST account with Orders and transfers on; MIT, Copyright (c) 2026 Autonomic Engineering.
// Setup only: namesilo-test-activation-test.php owns the assertions on this state.
declare(strict_types=1);
require __DIR__.'/namesilo-test-enrolled.php';
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true]);
$binding=AccountBindings::binding($account->fresh());
$writes->run($review('test_transfer'),['accept'=>true,'verified'=>true]);
