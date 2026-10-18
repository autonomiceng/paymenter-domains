<?php
// Synthetic paired-driver contract checks; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/FakeRegistrar.php';
require __DIR__.'/Support/FakeWriter.php';

use Paymenter\Extensions\Servers\Domains\Contracts\{RegistrarDriver, AdmittedRegistrarDriver};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AdmittedOperation, AttemptEvidence,
    Capabilities, Capability, CostUnit, DispatchSeal, DateEvidence, Discrepancy, Disposition, DomainSnapshot, DriverResult,
    Environment, Evidence, ExecutionRecord, ExpiryPrecision, Money, OrderEvidence, PrivacyMode, Query,
    QuoteEvidence, ReconciliationResult, RejectionBasis, RenewalStatus, RequestBudget, RequestContext, RequestOrigin, ResultReason,
    SendState, Support};
use Paymenter\Extensions\Servers\Domains\Tests\{FakeRegistrar, FakeWriter, FakeClock};

function refuses(callable $action, string $message): void
{
    try { $action(); } catch (InvalidArgumentException|RuntimeException|LogicException) { return; }
    throw new RuntimeException($message);
}
$now = new DateTimeImmutable('2026-10-17T12:00:00+00:00');
$query = new Query('example.com', Action::Register, 3);
$expiry = new DateEvidence('2029-10-17', ExpiryPrecision::Date, 'domain_info');
$precise = new DateEvidence('2029-10-17T00:00:00-07:00', ExpiryPrecision::Instant, 'registry_utc', 'America/Los_Angeles');
same('2029-10-17', $expiry->value, 'Date-only source has no manufactured instant');
same(null, $expiry->timezone, 'Date-only timezone remains unknown');
same(ExpiryPrecision::Instant, $precise->precision, 'Exact source precision retained');
foreach (['2029-02-30', '2029-1-01', '2029-10-17T00:00:00+00:00', "2029-10-17\0"] as $bad) {
    refuses(fn () => new DateEvidence($bad, ExpiryPrecision::Date, 'domain_info'), 'Invalid expiry accepted');
}
refuses(fn () => new DateEvidence('2029-10-17T00:00:00+00:00', ExpiryPrecision::Instant, 'domain_info', "UTC\0"), 'NUL timezone escaped validation');
refuses(fn () => new DateEvidence('2029-10-17T00:00:00+00:00', ExpiryPrecision::Instant, 'domain_info', 'Invalid/Zone'), 'Invalid timezone escaped validation');
refuses(fn () => new DateEvidence('2029-10-17T00:00:00+05:00', ExpiryPrecision::Instant, 'domain_info', 'America/Los_Angeles'), 'Contradictory timezone accepted');
refuses(fn () => new DateEvidence('2029-10-17', ExpiryPrecision::Date, 'domain_info', 'UTC'), 'Invented date-only zone accepted');
refuses(fn () => new DateEvidence('2029-10-17T00:00:00+00:00', ExpiryPrecision::Instant, 'domain_info'), 'Instant without zone provenance accepted');
foreach (['10.0000', '0.00', '123456789012345678.123456'] as $amount) {
    same($amount, (new Money($amount, 'USD'))->amount, 'Exact decimal precision retained');
}
foreach (['1e2', '-1.00', '01.00', '1.1234567', '9999999999999999999.00'] as $bad) {
    refuses(fn () => new Money($bad, 'USD'), 'Inexact money accepted');
}
refuses(fn () => new Money('1.00', 'usd'), 'Invalid currency accepted');
refuses(fn () => new Query('EXAMPLE.com', Action::Register, 1), 'Noncanonical query accepted');
refuses(fn () => new Query('xn--example.com', Action::Register, 1), 'Unsupported IDN accepted');
refuses(fn () => new Query('example.com', Action::Renew, 0), 'Invalid term accepted');
refuses(fn () => new QuoteEvidence($query, new Money('10.0000', 'USD'), CostUnit::Annual, 'catalogue', $now), 'Annual cost became multi-year total');
$quote = new QuoteEvidence($query, new Money('29.9500', 'USD'), CostUnit::TermTotal, 'term_quote', $now, $now->modify('+1 hour'));
refuses(fn () => new QuoteEvidence($query, null, CostUnit::TermTotal, 'term_quote', $now, $now->modify('-1 second')), 'Expired quote window accepted');
refuses(fn () => new Capability(Support::Unknown, [1]), 'Unverified terms advertised');
refuses(fn () => new Capability(Support::Supported, [1, 1]), 'Duplicate terms accepted');
refuses(fn () => new Capability(Support::Supported, ['1']), 'String capability term accepted');

foreach (['enom' => PrivacyMode::Paid, 'namesilo' => PrivacyMode::Free] as $registrar => $privacy) {
    $account = new AccountBinding($registrar, 11, Environment::Test, 'synthetic-account');
    $sameAccount = new AccountBinding($registrar, 11, Environment::Test, 'synthetic-account');
    expect($account->matches($sameAccount), 'Binding stable across credential rotation');
    foreach ([new AccountBinding($registrar, 12, Environment::Test, 'synthetic-account'),
        new AccountBinding($registrar, 11, Environment::Live, 'synthetic-account'),
        new AccountBinding($registrar, 11, Environment::Test, 'different-account'),
        new AccountBinding($registrar === 'enom' ? 'namesilo' : 'enom', 11, Environment::Test, 'synthetic-account')] as $other) {
        refuses(fn () => $account->requireMatch($other), 'Cross-account/environment/registrar binding accepted');
    }
    expect(!str_contains(var_export($account, true), 'synthetic-account'), 'Raw account identity exposed');
    $capabilities = new Capabilities($account, ['register' => new Capability(Support::Supported, [1, 3]),
        'renew' => new Capability(Support::Supported, [1]), 'transfer' => new Capability(Support::Unsupported)], $privacy);
    same(Support::Unknown, $capabilities->get('dns')->support, 'Missing capability remains unknown');
    expect(!$capabilities->get('register')->allows(2), 'Unlisted term inferred');
    same($privacy, $capabilities->privacy, 'Free/paid privacy semantics retained');
    $snapshot = new DomainSnapshot($account, 'example.com', true, $expiry, false, null, true);
    same(false, $snapshot->providerAutoRenew, 'Known false preserved');
    same(null, $snapshot->privacy, 'Unknown privacy preserved');
    same(null, $snapshot->providerReference, 'Provider ID is not mandatory');
    $evidence = new Evidence($snapshot, $quote, new Money('29.9500', 'USD'), new Money('0.00', 'USD'), premium: null);
    $fallback = new DriverResult($account, Disposition::AcceptedWithDiscrepancy, SendState::Sent,
        ResultReason::Fallback, $evidence, [Discrepancy::Contacts]);
    same(null, $fallback->evidence()->premium, 'Missing premium never means ordinary');
    same('0.00', $fallback->evidence()->balance->amount, 'Zero differs from unknown balance');
    foreach ([json_encode($fallback), var_export($fallback, true), print_r($fallback, true), print_r((array) $fallback, true)] as $summary) {
        expect(!str_contains($summary, 'example.com') && !str_contains($summary, '29.9500'), 'Summary leaked private facts');
    }
    refuses(fn () => serialize($fallback), 'Result serialization bypassed encrypted storage');
    refuses(fn () => clone $fallback, 'Result clone lost evidence');
    $unknown = new DriverResult($account, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved);
    same(null, $unknown->evidence()->charge, 'Unknown charge is not zero');
    same(RenewalStatus::Unknown, $unknown->evidence()->renewal, 'Unknown lifecycle stays unknown');
    $pending = new DriverResult($account, Disposition::Pending, SendState::Sent, ResultReason::Processing);
    $accepted = new DriverResult($account, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged);
    $unsent = new DriverResult($account, Disposition::Rejected, SendState::DefinitelyUnsent, ResultReason::Unsent,
        rejectionBasis: RejectionBasis::DefinitelyUnsent);
    $rejected = new DriverResult($account, Disposition::Rejected, SendState::Sent, ResultReason::ProvenRejected,
        rejectionBasis: RejectionBasis::ProviderProof);
    same(Disposition::Accepted, $accepted->disposition, 'Ack retained without completion flag');
    same(Disposition::Pending, $pending->disposition, 'Pending distinct from acceptance/completion');
    same(RejectionBasis::ProviderProof, $rejected->rejectionBasis, 'Provider proof differs from local unsent');
    same(SendState::DefinitelyUnsent, $unsent->sendState, 'Local unsent retained');
    refuses(fn () => new DriverResult($account, Disposition::Rejected, SendState::PossiblySent, ResultReason::Unresolved), 'Ambiguity became rejection');
    refuses(fn () => new DriverResult($account, Disposition::Rejected, SendState::Sent, ResultReason::ProvenRejected,
        $evidence, rejectionBasis: RejectionBasis::ProviderProof), 'Charged result became rejection');
    refuses(fn () => new DriverResult($account, Disposition::AcceptedWithDiscrepancy, SendState::Sent, ResultReason::Fallback), 'Unspecified discrepancy accepted');
    refuses(fn () => new DriverResult($account, Disposition::Accepted, SendState::DefinitelyUnsent, ResultReason::Acknowledged), 'Unsent acceptance accepted');

    refuses(fn () => new DriverResult($account, Disposition::Accepted, SendState::Sent, ResultReason::Unsent), 'Accepted reason contradicted disposition');
    refuses(fn () => new DriverResult($account, Disposition::Unknown, SendState::DefinitelyUnsent, ResultReason::Unresolved), 'Unknown asserted unsent');
    refuses(fn () => new DriverResult($account, Disposition::Rejected, SendState::DefinitelyUnsent, ResultReason::ProvenRejected,
        rejectionBasis: RejectionBasis::ProviderProof), 'Unsent provider rejection accepted');
    refuses(fn () => new DriverResult($account, Disposition::Rejected, SendState::Sent, ResultReason::ProvenRejected,
        new Evidence(candidates: [new OrderEvidence($account, $query, new Money('29.9500', 'USD'))]),
        rejectionBasis: RejectionBasis::ProviderProof), 'Charged candidate became rejection');
    refuses(fn () => new DriverResult($account, Disposition::Rejected, SendState::PossiblySent, ResultReason::ProvenRejected,
        rejectionBasis: RejectionBasis::ProviderProof), 'Ambiguous send became proven rejection');
    $mutableOrder = new OrderEvidence($account, $query, providerReference: 'order-42');
    $frozenEvidence = new Evidence(candidates: [&$mutableOrder]);
    $frozenReject = new DriverResult($account, Disposition::Rejected, SendState::Sent, ResultReason::ProvenRejected,
        $frozenEvidence, rejectionBasis: RejectionBasis::ProviderProof);
    $mutableOrder = new OrderEvidence($account, new Query('other.com', Action::Register, 1), new Money('10.00', 'USD'), 'other-order');
    same(null, $frozenReject->evidence()->candidates[0]->charge, 'Referenced candidate bypassed rejection validation');
    same('example.com', $frozenEvidence->candidates[0]->query->name, 'Referenced candidate changed frozen query');
    $term = 1; $frozenCapability = new Capability(Support::Supported, [&$term]); $term = 10;
    expect(!$frozenCapability->allows(10), 'Referenced term changed capability');
    $action = new Capability(Support::Unsupported); $frozenCaps = new Capabilities($account, ['register' => &$action]);
    $action = new Capability(Support::Supported, [1]);
    same(Support::Unsupported, $frozenCaps->get('register')->support, 'Referenced action upgraded capability');
    $discrepancy = Discrepancy::Contacts;
    $frozenResult = new DriverResult($account, Disposition::AcceptedWithDiscrepancy, SendState::Sent, ResultReason::Fallback, discrepancies: [&$discrepancy]);
    $discrepancy = Discrepancy::Charge;
    same([Discrepancy::Contacts], $frozenResult->discrepancies, 'Referenced discrepancy changed result');

    // An existing owned domain is valid prior context for an unsent renewal; it proves no execution.
    $unsentWithPriorDomain = new DriverResult($account, Disposition::Rejected, SendState::DefinitelyUnsent, ResultReason::Unsent,
        new Evidence(domain: $snapshot), rejectionBasis: RejectionBasis::DefinitelyUnsent);
    same(true, $unsentWithPriorDomain->evidence()->domain->owned, 'Prior ownership lost on local refusal');

    $makeContext = fn (int $limit = 20) => new RequestContext($account, RequestOrigin::Background, $now->modify('+10 seconds'), new RequestBudget($limit), new FakeClock($now));
    $fake = new FakeRegistrar($account, $capabilities, $fallback);
    $context = $makeContext(2);
    $fake->readDomain('example.com', $context); $fake->quote($query, $context);
    refuses(fn () => $fake->balance($context), 'Budget permitted extra request');
    same(2, count($fake->calls), 'Budget refusal never reaches fake wire');
    $expired = new RequestContext($account, RequestOrigin::Interactive, $now, new RequestBudget(1), new FakeClock($now));
    refuses(fn () => $fake->balance($expired), 'Deadline boundary escaped');
    same(1, $expired->budget->remaining(), 'Deadline refusal consumed budget');
    $cross = new RequestContext(new AccountBinding($registrar, 12, Environment::Test, 'synthetic-account'), RequestOrigin::Background,
        $now->modify('+1 hour'), new RequestBudget(1), new FakeClock($now));
    refuses(fn () => $fake->balance($cross), 'Wrong binding sent a request');
    same(1, $cross->budget->remaining(), 'Binding refusal consumed budget');

    $clock = new FakeClock($now);
    $moving = new RequestContext($account, RequestOrigin::Background, $now->modify('+1 second'), new RequestBudget(2), $clock);
    $fake->balance($moving); $clock->instant = $now->modify('+1 second');
    refuses(fn () => $fake->balance($moving), 'Stale driver clock escaped context deadline');
    same(1, $moving->budget->remaining(), 'Elapsed deadline consumed budget');

    foreach ([ExecutionRecord::Absent, ExecutionRecord::RecordedNull, ExecutionRecord::Recorded] as $execution) {
        $attempt = new AttemptEvidence($account, $query, 'operation-1', 7, $execution, SendState::PossiblySent, $now, $now, $expiry);
        same($execution, $attempt->execution, 'Missing/null/recorded attempt distinction lost');
        $unknownFake = new FakeRegistrar($account, $capabilities, $unknown);
        same(Disposition::Unknown, $unknownFake->reconcile($attempt, $makeContext())->disposition, 'Uncertain attempt guessed resolved');
        same(['reconcile'], $unknownFake->calls, 'Reconciliation replayed mutation');
        if ($execution !== ExecutionRecord::Absent) {
            refuses(fn () => new AttemptEvidence($account, $query, 'operation-1', 7, $execution, SendState::DefinitelyUnsent, $now, $now), 'Recorded-null marked definitely unsent');
        }
    }
    $sentAttempt = new AttemptEvidence($account, $query, 'operation-sent', 7, ExecutionRecord::Recorded, SendState::Sent,
        $now, $now, $expiry, seal: DispatchSeal::Sealed, providerReference: 'order-42', expectedExpiry: $expiry);
    refuses(fn () => (new FakeRegistrar($account, $capabilities, $unsent))->reconcile($sentAttempt, $makeContext()), 'Reconciliation relabelled sent attempt unsent');
    $wrongName = new DriverResult($account, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved,
        new Evidence(domain: new DomainSnapshot($account, 'other.com')));
    refuses(fn () => new ReconciliationResult($sentAttempt, $wrongName), 'Wrong-domain reconciliation accepted');
    $wrongTerm = new DriverResult($account, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved,
        new Evidence(candidates: [new OrderEvidence($account, new Query('example.com', Action::Renew, 1))]));
    refuses(fn () => new ReconciliationResult($sentAttempt, $wrongTerm), 'Wrong-action reconciliation accepted');
    $keyed = new DriverResult($account, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved,
        new Evidence(candidates: [new OrderEvidence($account, $query, new Money('29.9500', 'USD'), 'order-42')]));
    $keyedFake = new FakeRegistrar($account, $capabilities, $keyed);
    $matched = $keyedFake->reconcile($sentAttempt, $makeContext()); $matched->requireAttempt($sentAttempt);
    same(['order-42'], $keyedFake->reconciliationReferences, 'Original Enom order reference lost');
    same($expiry, $matched->attempt()->expectedExpiry, 'Expected post-operation expiry lost');
    $differentAttempt = new AttemptEvidence($account, $query, 'different-operation', 7, ExecutionRecord::Recorded, SendState::Sent, $now, $now);
    refuses(fn () => $matched->requireAttempt($differentAttempt), 'Different operation consumed reconciliation');
    foreach ([DispatchSeal::Unknown, DispatchSeal::Sealed] as $seal) {
        refuses(fn () => new AttemptEvidence($account, $query, 'operation-1', 7, ExecutionRecord::Absent, SendState::DefinitelyUnsent,
            $now, $now, seal: $seal), 'Unknown/present seal became unsent');
    }
    refuses(fn () => new AttemptEvidence($account, $query, 'operation-1', 7, ExecutionRecord::Absent, SendState::DefinitelyUnsent,
        $now, $now, seal: DispatchSeal::Absent, providerReference: 'order-42'), 'Provider order became unsent');
    new AttemptEvidence($account, $query, 'operation-1', 7, ExecutionRecord::Absent, SendState::DefinitelyUnsent, $now, $now, seal: DispatchSeal::Absent);
    refuses(fn () => new AdmittedOperation($account, $query, 'operation-1', 'claim-1', 7, 'paid-line-1', str_repeat('a', 64), null), 'Unknown cost admitted');
    refuses(fn () => new AdmittedOperation($account, new Query('example.com', Action::Renew, 1), 'operation-1', 'claim-1', 7,
        'paid-line-1', str_repeat('a', 64), new Money('10.00', 'USD')), 'Renewal without prior expiry admitted');
    $renewal = new AdmittedOperation($account, new Query('example.com', Action::Renew, 1), 'renew-1', 'claim-1', 7,
        'paid-line-1', str_repeat('a', 64), new Money('10.00', 'USD'), $expiry);
    (new FakeWriter($account, $capabilities, $accepted))->renew($renewal, $makeContext());
    foreach ([json_encode($matched), var_export($matched, true), print_r((array) $matched, true)] as $summary) {
        expect(!str_contains($summary, 'example.com') && !str_contains($summary, '29.9500'), 'Reconciliation leaked private facts');
    }

    $orders = [new OrderEvidence($account, $query, new Money('29.9500', 'USD')),
        new OrderEvidence($account, $query, new Money('29.9500', 'USD'), 'opaque-order')];
    $ambiguous = new DriverResult($account, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved, new Evidence(candidates: $orders));
    same(2, count($ambiguous->evidence()->candidates), 'Multiple candidates silently collapsed');
    $operation = new AdmittedOperation($account, $query, 'operation-1', 'claim-1', 7, 'paid-line-1', str_repeat('a', 64), new Money('29.9500', 'USD'));
    $writer = new FakeWriter($account, $capabilities, $fallback);
    expect(!$fake instanceof AdmittedRegistrarDriver && !method_exists($fake, 'register'), 'Read handle exposed write capability');
    expect(!$writer instanceof RegistrarDriver, 'Write handle exposed reader interface');
    same('29.9500', $operation->authorizedCost->amount, 'Authorized cost retained exactly');
    $writer->register($operation, $makeContext());
    refuses(fn () => $writer->renew($operation, $makeContext()), 'Action mismatch simulated a write');
    $transfer = new AdmittedOperation($account, new Query('example.com', Action::Transfer, 1), 'operation-2', 'claim-2', 7,
        'paid-line-2', str_repeat('a', 64), new Money('8.9500', 'USD'));
    refuses(fn () => $writer->transferIn($transfer, $makeContext()), 'Unsupported transfer simulated a write');
    refuses(fn () => $writer->register(new AdmittedOperation($cross->binding, $query, 'operation-x', 'claim-x', 7,
        'paid-line-x', str_repeat('a', 64), new Money('29.9500', 'USD')), $makeContext()), 'Wrong admitted binding simulated a write');
    foreach ([RegistrarDriver::class, AdmittedRegistrarDriver::class, FakeRegistrar::class, FakeWriter::class] as $type) {
        expect(!method_exists($type, 'execute'), 'Generic spending escape hatch introduced');
    }
}
$other = new AccountBinding('enom', 12, Environment::Test, 'synthetic-other');
refuses(fn () => new DriverResult($account, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved,
    new Evidence(domain: new DomainSnapshot($other, 'example.com'))), 'Wrong-account result evidence accepted');
echo "Driver contracts: paired binding, precise/unknown evidence, outcomes, budgets and non-dispatchable writes passed.\n";
