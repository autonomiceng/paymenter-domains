<?php
// Enom d1392a8 stale/overrun/ambiguous renewal scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/renewal-initialized.php';
$fixtureInitialization=initializedRenewalFixture(...);
require_once __DIR__.'/Support/reserved-fixtures.php';
$renewalFixtureNames=['completion-second-owner','completion-second-id','completion-drift','completion-auto-drift','completion-expiry-race'];
require __DIR__.'/Support/renewal-batch-fixture.php';
use App\Models\{InvoiceTransaction, ServiceCancellation};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DateEvidence, ExpiryPrecision, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
$invoicePresenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter(new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts),$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
$admissionReads = [ReadCommand::Info, ReadCommand::RenewalInfo, ReadCommand::Check, ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Balance];
$confirmationReads = [ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Info, ReadCommand::Expiry, ReadCommand::Order];
$completionCases=['second_owner','second_id','drift','auto_drift','expiry_race'];
require __DIR__.'/Support/renewal-completion-cases.php';
same([],array_keys($registrations),'Every independent renewal scenario ran');
echo "Completion races and incurred drift passed\n";$nativeSuccessful=true;
