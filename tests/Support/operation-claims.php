<?php
// Synthetic Enom 444f31c claim/seal regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
// These Enom claim/seal cases need native funding, but no second registrar account or catalogue.
$fixtureRegistrars = ['enom'];
require __DIR__.'/operation-fixture.php';
require_once __DIR__.'/reserved-fixtures.php';
$createOperation = $new;
$new = static function (string $name, bool $paid = true, bool $setup = true, string $action = 'register', int $term = 1) use ($createOperation) {
    return $createOperation(reservedFixtureName($name), $paid, $setup, $action, $term);
};
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, OperationPreflight};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, Capabilities, Capability, DispatchReadiness, Disposition, DriverResult, Evidence, Money, RequestContext, ResultReason, SendState, Support};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, DomainOperations, InvoiceBinding, OperationClaims};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
