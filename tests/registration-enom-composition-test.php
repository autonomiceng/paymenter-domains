<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-enom.php';
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, NameserverEvidence, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, DomainSummaries, WriteAcceptance, WriteFeatures};
$makeEngine($binding,$exchange);
// Protected scope is shared by sibling subclasses: concrete guard must reject before the first port call.
$attacker = new class extends RegistrationTransport {
    public function attack($target, $op, $ctx): DriverResult { return $target->sendRegistration($op, $ctx, [], 'https://resellertest.enom.com/interface.asp', 25000); }
    protected function sendRegistration(AdmittedOperation $o, RequestContext $c, array $p, string $s, int $t): DriverResult { throw new LogicException; }
};
$forged = new AdmittedOperation($binding, new Query(reservedFixtureName('forged.example.com'), Action::Register, 1), 'forged', 'claim', 1, 'funding', str_repeat('a', 64), new Money('1.00', 'USD'));
$ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+25 seconds'), new RequestBudget(10), $clock);
$before = $registration->calls; commerceReject(fn () => $attacker->attack($protocol, $forged, $ctx)); same($before, $registration->calls, 'Sibling forged descriptor has zero wire calls');
// Gate identity is mandatory before claim, not just equal gate values.
$makeEngine($binding, $exchange); $op = $new('composition.example.com');
commerceReject(fn () => $bridge->requireComposition($fresh, new WriteFeatures(registration: true), $acceptance, $protocol));
commerceReject(fn () => $bridge->requireComposition($fresh, new WriteFeatures(registration: true), new WriteAcceptance($binding, true, true, 25,catalogue: $GLOBALS['caps']??null), $protocol));
$engine = new DomainOperations($claims, $preflight, $bridge, $clock);
commerceReject(fn () => $engine->execute($op->id)); same(null, $op->fresh()->attempt, 'Synthetic preflight cannot authorize protocol');
try { $engine->consume($forged, $ctx, null); expect(false, 'Null acceptance prohibited'); } catch (TypeError) {}
// A non-bridge writer cannot route a real protocol through an otherwise valid engine grant.
$features = new WriteFeatures(registration: true); $makeEngine($binding, $exchange, $features);
$rogue = new class($protocol, $features, $acceptance) implements \Paymenter\Extensions\Servers\Domains\Contracts\AdmittedRegistrarDriver {
    public $engine;
    public function __construct(private $protocol, private $features, private $accepted) {}
    public function register(AdmittedOperation $op, RequestContext $ctx): DriverResult { return $this->protocol->register($op, $ctx, $this->engine, $this->features, $this->accepted); }
    public function transferIn(AdmittedOperation $op, RequestContext $ctx): DriverResult { throw new LogicException; }
    public function renew(AdmittedOperation $op, RequestContext $ctx): DriverResult { throw new LogicException; }
};
$engine = new DomainOperations($claims, $fresh, $rogue, $clock); $rogue->engine = $engine;
$before = $registration->calls; $result = $runRegistration($new('rogue-writer.example.com'));
same('uncertain', $result->state, 'Refused production composition stays sealed'); same($before, $registration->calls, 'Nonbridge writer sends zero requests');
$nativeSuccessful=true;echo "Enom registration scenario passed.\n";
