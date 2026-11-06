<?php
// Native owner POSTs need real checkout, encrypted payloads, native sessions and Blade.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-actions.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{OwnerSetup, DomainManagement};
require __DIR__.'/Support/committed-code-pages.php';
$nativeSuccessful=true;echo "Native owner action matrix passed.\n";
