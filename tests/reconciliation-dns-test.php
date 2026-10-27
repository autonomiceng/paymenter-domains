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
echo "Optional DNS failures preserve registration proof passed\n"; $nativeSuccessful = true;
