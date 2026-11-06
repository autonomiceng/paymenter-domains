<?php
// Native renewal refunds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/renewal-initialized.php';
require_once __DIR__.'/Support/scenario-inventory.php';
$fixtureInitialization=initializedRenewalFixture(...);
$renewalFixtureNames=['renew-credit-replace'];
require __DIR__.'/Support/renewal-refund.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
// Exact old credit settlement automatically authorizes an ordinary replacement invoice, with no staff step.
[$returned,$returnedDomain]=$makeRenewal(placeholderComFixtureName('renew-credit-replace-placeholder.com'));$clock->instant=new DateTimeImmutable('2027-10-17T12:00:00Z');$returned->update(['state'=>'held','safe_code'=>'renewal_funding_or_authority']);
$refunds->refundHeld($staff,$returned->id,'service_cancelled');
\Paymenter\Extensions\Servers\Domains\Services\RenewalReplacements::recoverDomain($returnedDomain,$clock);
expect($billing->requestOptions($returnedDomain)['terms']!==[],'Settlement recovery unblocks the owner renewal preview without issuing debt');
$replacement=$billing->invoice($returnedDomain->id,$user);
expect($replacement->id!==$returned->invoice_id,'Exact full returned credit releases ordinary replacement');
same(null,$returned->fresh()->policy['renewal_replacement']['actor_id'],'System replacement audit has no invented staff actor');
same($replacement->id,$billing->invoice($returnedDomain->id,$user)->id,'Repeated invoice recovery cannot duplicate debt');
same([],array_keys($registrations),'Every independent refund scenario ran');
echo "Exact old credit settlement releases one replacement invoice\n";$nativeSuccessful=true;
