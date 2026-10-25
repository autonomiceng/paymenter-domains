<?php
// Enom 444f31c reconciliation-registration/provider-disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/reconciliation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, Disposition, DriverResult, Evidence, ExpiryPrecision, HistoryAcceptance, RegistrationEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances, ReconciliationEvidence, RenewalAuthority};
// Exercise optional read failures through the real Client and RequestContext admission.
require_once __DIR__.'/Support/WireLimit.php';
$dnsFault = (object) ['mode' => ''];
$dnsWire = new class($wire, $observation, $dnsFault) implements \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Transport {
    public function __construct(private $inner, private $state, private $fault) {}
    public function send(\Paymenter\Extensions\Servers\Domains\Data\Environment $env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        if ($command !== ReadCommand::Nameservers) { return $this->inner->send($env, $command, $parameters, $uid, $token, $timeoutMs); }
        $this->state->calls[] = $command;
        if ($this->fault->mode === 'cap') { throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit; }
        if ($this->fault->mode === 'transport') { throw new RuntimeException('Synthetic transport failure'); }
        if (in_array($this->fault->mode, ['refused', 'refused-strict'], true)) {
            return '<interface-response><Command>GetDNS</Command><ErrCount>1</ErrCount><Done>true</Done><errors><Err1>DNS read refused</Err1></errors></interface-response>';
        }
        $dns = match ($this->fault->mode) {
            'malformed' => '<dns>invalid hostname</dns>',
            'duplicate' => '<dns>ns1.provider.example</dns><dns>ns1.provider.example</dns>',
            'shape' => '<dns><host>ns1.provider.example</host></dns>',
            default => '<dns>ns1.provider.example</dns><dns>ns2.provider.example</dns>',
        };
        return '<interface-response><Command>GetDNS</Command><ErrCount>0</ErrCount><Done>true</Done>'.$dns.'</interface-response>';
    }
};
$dnsRegistry = new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$dnsRegistry->register('enom', ['token'], $verify, fn ($b, $c) => new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(
    new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client($b, 'account-enom', $c['token'], $dnsWire), [$fixtureEnding]));
$dnsBindings = new \Paymenter\Extensions\Servers\Domains\Services\AccountBindings($dnsRegistry);
$dnsReconcile = new \Paymenter\Extensions\Servers\Domains\Services\OperationReconciliation($claims, $dnsBindings, $clock);
$proofCommands = [ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Lock, ReadCommand::Privacy, ReadCommand::Info, ReadCommand::Expiry, ReadCommand::Order];
// Limiter admission is outside Client's transport catch. Driver must preserve programmer failures.
[$op, $binding] = $prepare(reservedFixtureName('dns-programmer.example.com'));
$dnsFault->mode = '';
$attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
foreach ([new Error('DNS admission error'), new TypeError('DNS admission type error'),
    new LogicException('DNS admission logic error'), new RuntimeException('DNS admission runtime error'),
    new DomainException('Unexpected DNS admission refusal'), new DomainException('Other provider refusal'),
    new class('Registrar read failed.') extends DomainException {},
    new class('Registrar request budget exhausted.') extends RuntimeException {},
    new class('Registrar request deadline reached.') extends RuntimeException {}] as $error) {
    $observation->calls = [];
    $limiter = new WireLimit(8);
    $limiter->onAcquire = static function () use ($limiter, $error): void { if ($limiter->calls === 8) { throw $error; } };
    $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
        \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
        new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock, $limiter);
    $money = $nativeMoney(); $writes = $writer->calls; $caught = null;
    try { $dnsBindings->reader($binding->serverId)->reconcile($attempt, $context); } catch (Throwable $failure) { $caught = $failure; }
    expect($caught === $error, 'Registration propagates exact DNS admission programmer exception: '.$error::class);
    same([$proofCommands, 8, 7, $money, $writes], [$observation->calls, $limiter->calls, $limiter->released, $nativeMoney(), $writer->calls],
        'Registration reaches optional admission only after proof, without a DNS wire or purchase');
}
$parserInstant = $clock->now();
foreach ([new Error('DNS evidence clock failure'), new DomainException('Registrar read failed.'),
    new RuntimeException('Registrar request budget exhausted.')] as $parserError) {
    $parserTime = new class($parserInstant->format(DATE_ATOM)) extends DateTimeImmutable {
        public Throwable $failure;
        public function setTimezone(DateTimeZone $timezone): DateTimeImmutable { throw $this->failure; }
    };
    $parserTime->failure = $parserError;
    $limiter = new WireLimit(8);
    $limiter->onAcquire = static function () use ($limiter, $clock, $parserTime): void { if ($limiter->calls === 8) { $clock->instant = $parserTime; } };
    $observation->calls = [];
    $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
        \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
        new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock, $limiter);
    $caught = null;
    try { $dnsBindings->reader($binding->serverId)->reconcile($attempt, $context); } catch (Throwable $failure) { $caught = $failure; }
    expect($caught === $parserError, 'Registration propagates exact DNS evidence programming error');
    same([[...$proofCommands, ReadCommand::Nameservers], 8, 8], [$observation->calls, $limiter->calls, $limiter->released],
        'Registration programming error occurs after the admitted DNS wire and release');
    $clock->instant = $parserInstant;
}
[$op, $binding] = $prepare(reservedFixtureName('dns-refusal.example.com'));
$attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
foreach (['refused', 'refused-strict', 'transport', 'malformed', 'duplicate', 'shape'] as $fault) {
    $dnsFault->mode = $fault; $observation->calls = [];
    $limiter = new WireLimit(8);
    $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
        \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
        new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock, $limiter, stopOnReadFailure: $fault === 'refused-strict');
    $money = $nativeMoney(); $writes = $writer->calls;
    $facts = $dnsBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
    $proof = ReconciliationEvidence::completion($attempt, $facts, $clock->now());
    expect($proof !== null, 'Registration retains proof after expected DNS failure: '.$fault);
    same(['9001', ['amount' => '8.95', 'currency' => 'USD'], '2027-10-18', '456', null],
        [$proof['order_reference'], $proof['charge'], $proof['expiry'], $proof['provider_domain_id'], $facts->domain->nameservers],
        'Registration DNS failure preserves money and ownership without invented nameservers: '.$fault);
    same([[...$proofCommands, ReadCommand::Nameservers], 8, 8, $money, $writes],
        [$observation->calls, $limiter->calls, $limiter->released, $nativeMoney(), $writer->calls], 'Registration DNS failure releases admission without writes: '.$fault);
}
$dnsFault->mode = '';
foreach (['budget', 'deadline'] as $fault) {
    $observation->calls = []; $instant = $clock->now(); $deadline = $instant->modify('+250 seconds');
    $limiter = new WireLimit(8);
    if ($fault === 'deadline') {
        $limiter->onAcquire = static function () use ($limiter, $clock, $deadline): void { if ($limiter->calls === 8) { $clock->instant = $deadline; } };
    }
    $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
        \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $deadline,
        new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget($fault === 'budget' ? 7 : 25), $clock, $limiter);
    $facts = $dnsBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
    $clock->instant = $instant;
    expect(ReconciliationEvidence::completion($attempt, $facts, $clock->now()) !== null, 'Registration retains proof after expected local '.$fault.' exhaustion');
    same(null, $facts->domain->nameservers, 'Local capacity failure supplies no nameserver evidence');
    same([$proofCommands, $fault === 'budget' ? 7 : 8, $fault === 'budget' ? 7 : 8],
        [$observation->calls, $limiter->calls, $limiter->released], 'Local exhaustion occurs after proof without DNS transport');
}
foreach (['cap', 'deferred', 'observed'] as $fault) {
    [$op, $binding] = $prepare(reservedFixtureName('dns-'.$fault.'.example.com'));
    $dnsFault->mode = $fault;
    $attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
    $limiter = new WireLimit($fault === 'deferred' ? 7 : 8);
    $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
        \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
        new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock, $limiter);
    $facts = $dnsBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
    $proof = ReconciliationEvidence::completion($attempt, $facts, $clock->now());
    expect($proof !== null, 'Optional registration DNS '.$fault.' retains completion proof');
    same(['9001', ['amount' => '8.95', 'currency' => 'USD'], '2027-10-18', '456', 'captured_order'],
        [$proof['order_reference'], $proof['charge'], $proof['expiry'], $proof['provider_domain_id'], $proof['source']], 'Registration retains original financial and domain proof: '.$fault);
    same([null, 'unknown', false], [$facts->transferReference, $facts->transfer->value, $facts->historyComplete], 'Registration retains original evidence: '.$fault);
    $expectedCommands = $fault === 'deferred' ? $proofCommands : [...$proofCommands, ReadCommand::Nameservers];
    same([$expectedCommands, 8, $fault === 'deferred' ? 7 : 8], [$observation->calls, $limiter->calls, $limiter->released], 'Exact registration proof wires and final admission: '.$fault);
    same($fault === 'observed' ? ['ns1.provider.example', 'ns2.provider.example'] : null, $facts->domain->nameservers?->nameservers, 'Only successful GetDNS supplies observed values: '.$fault);
    if ($fault === 'observed') { same(['observed', 'enom_getdns'], [$facts->domain->nameservers->state, $facts->domain->nameservers->source], 'GetDNS provenance retained'); }
    $observation->calls = []; $limiter = new WireLimit($fault === 'deferred' ? 7 : 8);
    $savedBindings = app()->getBindings();
    $runtimeClass = \Paymenter\Extensions\Servers\Domains\Services\Runtime::class;
    $limiterClass = \Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class;
    $savedInstances = [];
    foreach ([$runtimeClass, $limiterClass] as $class) { if (app()->bound($class)) { $savedInstances[$class] = app($class); } }
    app()->instance($runtimeClass, new stdClass); app()->instance($limiterClass, $limiter);
    try {
        $money = $nativeMoney(); $writes = $writer->calls;
        $result = $dnsReconcile->run($op->id);
        same('succeeded', $result->fresh()->state, 'Registration persists proven completion after DNS '.$fault);
        same($proof, $result->attempt['reconciliation'], 'Persisted proof is the original proof: '.$fault);
        same(['9001', ['amount' => '8.95', 'currency' => 'USD'], '2027-10-18', '456'],
            [$result->provider_order_id, $result->cost['actual'], $result->confirmed_expires_at->format('Y-m-d'), $result->domain->fresh()->provider_domain_id], 'Persisted order, USD charge, expiry and domain ID: '.$fault);
        same($fault === 'observed' ? 'observed' : null, $result->domain->fresh()->observation['nameservers']['state'] ?? null, 'No invented observed DNS: '.$fault);
        same([$money, $writes], [$nativeMoney(), $writer->calls], 'Registration preserves native money and never purchases again: '.$fault);
        same([$expectedCommands, 8], [$observation->calls, $limiter->calls], 'Persistence uses exact proof reads: '.$fault);
        $retained = [$result->fresh()->getRawOriginal(), $result->domain->fresh()->getRawOriginal()];
        $dnsReconcile->run($op->id); $engine->execute($op->id);
        same([$expectedCommands, 8, $money, $writes, $retained], [$observation->calls, $limiter->calls, $nativeMoney(), $writer->calls,
            [$result->fresh()->getRawOriginal(), $result->domain->fresh()->getRawOriginal()]], 'Completed registration repeat adds no I/O or writes: '.$fault);
    } finally {
        foreach ([$runtimeClass, $limiterClass] as $class) {
            app()->offsetUnset($class);
            if (isset($savedBindings[$class])) { app()->bind($class, $savedBindings[$class]['concrete'], $savedBindings[$class]['shared']); }
            if (isset($savedInstances[$class])) { app()->instance($class, $savedInstances[$class]); }
        }
    }
}
[$op, $binding] = $prepare(reservedFixtureName('dns-unproven.example.com')); $dnsFault->mode = '';
$observation->fault = 'zero';
$attempt = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($op, $op->domain, $binding);
$context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding, \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,
    $clock->now()->modify('+250 seconds'), new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock);
$facts = $dnsBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
same(null, \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::completion($attempt, $facts, $clock->now()), 'Missing charge cannot prove completion');
same(null, $facts->domain->nameservers, 'Unproven completion never enriches DNS');
expect(!in_array(ReadCommand::Nameservers, $observation->calls, true), 'Missing financial proof never reads DNS');
[$op, $binding] = $prepare(reservedFixtureName('dns-preproof.example.com')); $dnsFault->mode = '';
$attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding); $limiter = new WireLimit(6);
$context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
    \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
    new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock, $limiter);
try { $dnsBindings->reader($binding->serverId)->reconcile($attempt, $context); throw new LogicException('Pre-proof refusal was swallowed'); }
catch (\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred) {}
same([array_slice($proofCommands, 0, 6), 7, 6], [$observation->calls, $limiter->calls, $limiter->released], 'Pre-proof refusal still escapes before order/DNS');
// The captured ID identifies the order even when its local clock has no accepted zone.
[$op, $binding] = $prepare(reservedFixtureName('local-clock.example.com'));
$observation->billDate = '10/18/2026 6:02:00 AM'; // UTC-6 fits; LA interprets this one hour late.
$result = $reconcile->run($op->id);
same('succeeded', $result->state, 'Captured order local clock completes independently of an assumed provider zone');
$proof = $result->attempt['reconciliation'];
same(['2026-10-18T06:02:00', 'local', 'enom_order_bill_date', null],
    [$proof['processed_at'], $proof['processed_precision'], $proof['processed_source'], $proof['processed_timezone']], 'Observed wall clock retains unknown zone');
same(['8.95', '2027-10-18'], [$result->cost['actual']['amount'], $proof['expiry']], 'Exact charge and full-term expiry still required');
expect(!in_array(ReadCommand::Orders, $observation->calls, true), 'Captured ID bypasses history discovery');
expect(RenewalAuthority::completed($result, $result->domain, $binding), 'Local-clock registration remains a valid future renewal anchor');
foreach (['processed_at' => '2026-10-19T02:05:01', 'order_reference' => '9002', 'source' => 'accepted_history'] as $key => $value) {
    $tampered = clone $result; $tampered->attempt = [...$result->attempt, 'reconciliation' => [...$proof, $key => $value]];
    expect(!RenewalAuthority::completed($tampered, $result->domain, $binding), 'Retained anchor rechecks local bounds and captured identity: '.$key);
}
// Bounds are asymmetric: local = UTC + an offset from -12 through +14 hours.
foreach (['lower' => '10/18/2026 12:00:00 AM', 'upper' => '10/19/2026 2:05:00 AM'] as $edge => $billDate) {
    [$op] = $prepare(reservedFixtureName('clock-'.$edge.'.example.com')); $observation->billDate = $billDate;
    same('succeeded', $reconcile->run($op->id)->state, 'Inclusive offset boundary completes: '.$edge);
}
foreach (['too-late', 'too-early', 'wrong-id', 'history'] as $fault) {
    [$op, $binding] = $prepare(reservedFixtureName('clock-'.$fault.'.example.com'), $fault === 'history' ? 'null' : 'accepted');
    $observation->billDate = match ($fault) {
        'too-late' => '10/19/2026 2:05:01 AM', 'too-early' => '10/17/2026 11:59:59 PM', default => '10/18/2026 6:02:00 AM',
    };
    if ($fault === 'wrong-id') { $observation->reference = '9002'; }
    if ($fault === 'history') {
        HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1'));
        $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    }
    $money = $nativeMoney(); $writes = $writer->calls; $result = $reconcile->run($op->id);
    expect($result->state !== 'succeeded', 'Unproven clock/identity refuses completion: '.$fault);
    same([null, null, null], [$result->cost['actual'] ?? null, $result->confirmed_expires_at,
        $result->domain->renewal_policy['billing_anchor'] ?? null], 'Refusal creates no financial anchor: '.$fault);
    same([$money, $writes], [$nativeMoney(), $writer->calls], 'Clock reconciliation preserves finance and never writes: '.$fault);
    if ($fault === 'history') { expect(in_array(ReadCommand::Orders, $observation->calls, true), 'Skewed negative really used strict history'); }
}
// A sealed unknown attempt is otherwise eligible; current native funding must refuse before any read.
foreach (['pending', 'paid'] as $invoiceStatus) {
    [$op, $binding] = $prepare(reservedFixtureName('initially-unfunded-'.$invoiceStatus.'.example.com'), 'null');
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1'));
    $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    $attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
    expect($attempt->historyAcceptance?->covers($attempt, $clock->now()), 'M9 '.$invoiceStatus.' history gate permits observation');
    Illuminate\Support\Facades\DB::table('invoice_transactions')->where('invoice_id', $op->invoice_id)->delete();
    Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => $invoiceStatus]);
    $money = $nativeMoney(); $writes = $writer->calls;
    $result = $reconcile->run($op->id);
    same([[], $writes], [$observation->calls, $writer->calls], 'M9 '.$invoiceStatus.' reconciliation makes zero provider calls');
    same(['uncertain', 'reconciliation_required', 'registration_pending', $op->domain->domain, null, null, null, null],
        [$result->state, $result->safe_code, $result->domain->status, $result->domain->reservation_name,
            $result->domain->provider_expires_at, $result->confirmed_expires_at, $result->cost['actual'] ?? null,
            $result->domain->renewal_policy['billing_anchor'] ?? null], 'M9 '.$invoiceStatus.' remains unresolved and reserved');
    same($money, $nativeMoney(), 'M9 '.$invoiceStatus.' preserves invoices, items, transactions, credits and jobs');
}
[$op] = $prepare(reservedFixtureName('unique-completion.example.com'));
same('registration_correction_required', $op->safe_code, 'Correction selector retained');
$result = $reconcile->run($op->id);
same('succeeded', $result->state, 'Unique original ID and actual charge complete');
same('8.95', $result->cost['actual']['amount'], 'Actual charge differs from authorized cost without floor');
same('active', $result->domain->status, 'Owned only on financial proof');
same('2027-10-18', $result->domain->provider_expires_at->format('Y-m-d'), 'Dedicated expiry and order corroborate despite purchase discrepancy');
same('2027-10-18', $result->domain->renewal_policy['billing_anchor']['expiry'], 'Financial anchor uses proven expiry');
same(null, App\Models\Service::find($op->service_id)->expires_at, 'Native annual expiry remains disabled');
same('paid', App\Models\Invoice::find($op->invoice_id)->status, 'Invoice untouched');
same(true, $result->policy['reconciliation']['staff_alert'], 'Corrections/changed cost alert staff');
same('authorization_required', $result->attempt['corrections']['nameservers']['state'], 'No implicit management write');
$before = $observation->calls; $reconcile->run($op->id); same($before, $observation->calls, 'Completed operation repeats without provider calls');
commerceReject(fn () => $result->update(['cost' => [...$result->cost, 'actual' => ['amount' => '0.00', 'currency' => 'USD']]]));
foreach (['zero', 'multiple', 'term'] as $fault) {
    [$op] = $prepare(reservedFixtureName('unresolved-'.$fault.'.example.com')); $observation->fault = $fault;
    $result = $reconcile->run($op->id); same('pending', $result->state, 'Missing/ambiguous/wrong-term evidence stays pending');
    same(null, $result->cost['actual'] ?? null, 'No manufactured actual charge');
    same(null, $result->domain->provider_expires_at, 'No anchor from provider observation alone');
    same(true, $result->policy['reconciliation']['staff_alert'], 'Durable unresolved alert');
}
foreach (['late', 'absent', 'null'] as $mode) {
    [$op, $binding] = $prepare(reservedFixtureName('recover-'.$mode.'.example.com'), $mode);
    $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    if ($mode !== 'late') { HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1')); }
    $result = $reconcile->run($op->id); same('succeeded', $result->state, 'Late facts/read-only provider history recover original unique attempt');
    if ($mode === 'absent') { expect(!array_key_exists('execution', $result->attempt), 'Recovery never fabricates missing execution'); }
    if ($mode === 'null') { same(null, $result->attempt['execution'], 'Recorded-null distinction preserved'); }
    if ($mode === 'null') {
        $afterRecovery = [$observation->calls, $preflight->calls, $writer->calls]; $money = $nativeMoney();
        $retained = [$result->operation_key, $result->getRawOriginal(), $result->domain->getRawOriginal()];
        $engine->execute($result->id);
        same($afterRecovery, [$observation->calls, $preflight->calls, $writer->calls], 'M10 execute after lost-result recovery adds no reads or writes');
        same([$money, $retained], [$nativeMoney(), [$result->fresh()->operation_key, $result->fresh()->getRawOriginal(), $result->domain->fresh()->getRawOriginal()]],
            'M10 execute retains the recovered key, charge, expiry, anchor and native finance');
        $reconcile->run($result->id);
        same($afterRecovery, [$observation->calls, $preflight->calls, $writer->calls], 'M10 reconcile after lost-result recovery adds no reads or writes');
        same([$money, $retained], [$nativeMoney(), [$result->fresh()->operation_key, $result->fresh()->getRawOriginal(), $result->domain->fresh()->getRawOriginal()]],
            'M10 reconcile retains the recovered key, charge, expiry, anchor and native finance');
    }
}
// Captured acceptance can retain execution facts without an order reference; history must still run.
[$op, $binding] = $prepare(reservedFixtureName('history.example.com'), 'absent');
$op = $claims->finish($op, new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
    new Evidence(registration: new RegistrationEvidence(\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op, $op->domain),
        null, new DateEvidence('2027-10-18', ExpiryPrecision::Date, 'synthetic_purchase'), null, []))));
HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1'));
$clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
$attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
expect($attempt->executionFacts !== null, 'Null-reference acceptance retains execution facts');
same(null, $attempt->providerReference, 'Null-reference acceptance has no captured order ID');
same(null, $op->attempt['evidence']['registration']['orderReference'], 'Registration evidence retained the null reference');
expect($attempt->historyAcceptance?->covers($attempt, $clock->now()), 'Saved history acceptance covers null-reference execution');
$money = $nativeMoney(); $writes = $writer->calls;
$result = $reconcile->run($op->id);
same('succeeded', $result->state, 'History completes accepted execution without a captured order reference');
expect(in_array(ReadCommand::Orders, $observation->calls, true), 'Null-reference execution reads Enom history');
same('accepted_history', $result->attempt['reconciliation']['source'], 'Completion uses accepted history rather than captured order');
same('9001', $result->provider_order_id, 'Unique history supplies the missing order reference');
same($writes, $writer->calls, 'Null-reference history recovery never replays a write');
same($money, $nativeMoney(), 'Null-reference history recovery preserves native finance');
// Null-reference acceptance never substitutes for a covering, unique history proof.
foreach (['no-acceptance', 'window', 'zero', 'multiple'] as $fault) {
    [$op, $binding] = $prepare(reservedFixtureName('null-history-'.$fault.'.example.com'), 'absent');
    $op = $claims->finish($op, new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
        new Evidence(registration: new RegistrationEvidence(\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op, $op->domain),
            null, new DateEvidence('2027-10-18', ExpiryPrecision::Date, 'synthetic_purchase'), null, []))));
    App\Models\Server::findOrFail($binding->serverId)->settings()->where('key', HistoryAcceptances::KEY)->delete();
    if ($fault !== 'no-acceptance') {
        HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', $fault === 'window' ? 600 : 0, 86400, 'enom_registration_v1'));
    }
    $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    $attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
    expect($attempt->executionFacts !== null && $attempt->providerReference === null, 'Negative retains accepted null-reference execution: '.$fault);
    same(!in_array($fault, ['no-acceptance', 'window'], true), $attempt->historyAcceptance?->covers($attempt, $clock->now()) ?? false,
        'Negative history acceptance coverage: '.$fault);
    $money = $nativeMoney(); $calls = [$preflight->calls, $writer->calls];
    if (in_array($fault, ['zero', 'multiple'], true)) {
        $observation->fault = 'history-'.$fault;
        $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
            \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
            new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock);
        $facts = $readBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
        same([true, $fault === 'zero' ? 0 : 2], [$facts->historyComplete, count($facts->candidates)], 'Negative has complete empty or competing history: '.$fault);
        $observation->calls = [];
    }
    $result = $reconcile->run($op->id); $engine->execute($op->id); $result = $result->fresh();
    same('pending', $result->state, 'Null-reference history refuses completion: '.$fault);
    same([null, null, null, null, $op->domain->domain], [$result->cost['actual'] ?? null, $result->confirmed_expires_at,
        $result->domain->provider_expires_at, $result->domain->renewal_policy['billing_anchor'] ?? null,
        $result->domain->reservation_name], 'Refused history retains reservation without charge or expiry anchor: '.$fault);
    same($calls, [$preflight->calls, $writer->calls], 'Refused history and execution never replay a write: '.$fault);
    same($money, $nativeMoney(), 'Refused history leaves native money unchanged: '.$fault);
    same(in_array($fault, ['zero', 'multiple'], true), in_array(ReadCommand::Orders, $observation->calls, true), 'Only accepted covering history reaches discovery: '.$fault);
    if (in_array($fault, ['no-acceptance', 'window'], true)) { same([], $observation->calls, 'Unaccepted history makes no provider reads: '.$fault); }
}
[$op] = $prepare(reservedFixtureName('timestamp-boundary.example.com'));
$beforeUpdate = Illuminate\Support\Carbon::parse($op->getRawOriginal('updated_at'));
// Eloquent samples time for the model, then again for the UPDATE when the first
// timestamp equals the original and is omitted from the dirty attributes.
$nativeDates = new class($beforeUpdate) extends Carbon\Factory {
    public int $reads = 0;
    public function __construct(private Illuminate\Support\Carbon $start) { parent::__construct(); }
    public function now($timezone = null): Illuminate\Support\Carbon
    {
        return $this->start->copy()->addSeconds($this->reads++ === 0 ? 0 : 1);
    }
};
$observation->during = static function () use ($op, $beforeUpdate, $nativeDates): void {
    same(2, $nativeDates->reads, 'Model and query builder sampled opposite sides of a second boundary');
    same($beforeUpdate->copy()->addSecond()->format('Y-m-d H:i:s'), $op->fresh()->getRawOriginal('updated_at'),
        'Unresolved alert persisted the later query-builder timestamp');
};
Illuminate\Support\Facades\Date::useFactory($nativeDates);
try {
    $result = $reconcile->run($op->id);
    same('succeeded', $result->state, 'Real original charge and expiry anchor survive timestamp boundary');
    same('8.95', $result->cost['actual']['amount'], 'Boundary completion retains proven charge');
    same('2027-10-18', $result->domain->renewal_policy['billing_anchor']['expiry'], 'Boundary completion retains proven anchor');
} finally { Illuminate\Support\Facades\Date::useDefault(); }
echo "Original-attempt unique completion, correction selection and crash recovery passed\n"; $nativeSuccessful = true;
