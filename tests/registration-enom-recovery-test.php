<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-enom.php';
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, NameserverEvidence, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, DomainSummaries, WriteAcceptance, WriteFeatures};
// One real bridge Purchase requests three years; both response and later owned readback prove only one.
require __DIR__.'/Support/reconciliation-fixture.php';
$registration->mode = 'clean'; $registration->calls = []; $registration->purchases = []; $makeEngine($binding, $exchange);
$op = $new('three-year-short-readback.example.com', term: 3); $money = $nativeMoney();
$result = $runRegistration($op);
same([3], array_column($registration->purchases, 'NumYears'), 'M8 exactly one Purchase requests the paid three-year term');
same(['pending', 'registration_evidence_required', '2027-10-18'],
    [$result->state, $result->safe_code, $result->attempt['registration']['response_expiry']], 'M8 short Purchase remains financially unresolved');
$observation->name = $op->domain->domain; $observation->years = 3; $observation->expiryYears = 1; $observation->reference = '1234';
same($result->attempt['evidence']['registration']['orderReference'], $observation->reference, 'Observed order matches the Purchase response');
$result = $reconcile->run($op->id);
same([\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Info,
    \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Renewal,
    \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Lock,
    \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Privacy,
    \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Info,
    \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Expiry,
    \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Order], $observation->calls,
    'M8 original order and dedicated one-year expiry are actually observed');
same(['pending', 'registration_evidence_required', 'original_attempt_unresolved', null, null, null, null, $op->domain->domain],
    [$result->state, $result->safe_code, $result->policy['reconciliation']['reason'], $result->confirmed_expires_at,
        $result->domain->provider_expires_at, $result->domain->renewal_policy['billing_anchor'] ?? null,
        $result->cost['actual'] ?? null, $result->domain->reservation_name], 'M8 one-year readback cannot confirm three years or release the name');
$calls = [$registration->reads, $registration->calls, $observation->calls]; $engine->execute($op->id);
same([$calls, $money], [[$registration->reads, $registration->calls, $observation->calls], $nativeMoney()], 'M8 unresolved short term cannot replay or change native finance');
// The real Purchase retained an ID but no expiry: later reads must settle without another Purchase.
$registration->mode = 'missingexpiry'; $registration->calls = []; $makeEngine($binding, $exchange);
$op = $new('captured-missing-expiry.example.com'); $result = $runRegistration($op);
same('missing', $result->attempt['evidence']['registration']['expiryIssue'], 'Missing expiry stores only its bounded reason');
$original = $result->attempt; $purchases = count($registration->purchases);
$observation->name = $op->domain->domain; $observation->years = 1; $observation->expiryYears = null; $observation->reference = '1234';
// Registration preflight authorized USD 6.00; later order proof must match it exactly.
$observation->fault = 'captured-charge';
$result = $reconcile->run($op->id);
same('succeeded', $result->state, 'Captured unknown/sent Purchase recovers from its exact full order proof');
same('unknown', $result->attempt['execution']['disposition'], 'Recovery preserves the original classification');
same($original, array_diff_key($result->attempt, ['reconciliation' => true]), 'Recovery preserves the seal and original reply facts');
$engine->execute($op->id); $reconcile->run($op->id);
same($purchases, count($registration->purchases), 'Captured recovery never repeats Purchase');


$nativeSuccessful=true;echo "Enom registration scenario passed.\n";
