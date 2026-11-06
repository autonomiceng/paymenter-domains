<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-registration-base.php';
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;
$makeEngine($binding,$exchange);
$attacker = new class extends RegistrationTransport {
    public function attack($target, $op, $ctx): DriverResult { return $target->sendRegistration($op, $ctx, [], 'https://ote.namesilo.com/apibatch', 25000); }
    protected function sendRegistration(AdmittedOperation $o, RequestContext $c, array $p, string $s, int $t): DriverResult { throw new LogicException; }
};
$forged = new AdmittedOperation($binding, new Query('forged-placeholder.com', Action::Register, 1), 'forged', 'claim', 1, 'funding', str_repeat('a', 64), new Money('1.00', 'USD'));
$ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+25 seconds'), new RequestBudget(10), $clock);
$before = $registration->calls; commerceReject(fn () => $attacker->attack($protocol, $forged, $ctx)); same($before, $registration->calls, 'NameSilo sibling guard makes zero calls');
foreach (['different', 'missing', 'closure', 'account', 'registrar'] as $mode) {
    $features = new \Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(registration: true); $makeEngine($binding, $exchange, $features);
    $other = new \Paymenter\Extensions\Servers\Domains\Services\RegistrationProfiles($binding, $claims, $clock, $exchange);
    if ($mode === 'different') { $protocol = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration($binding, $other, $exchange); }
    if ($mode === 'account') { $protocol = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration(new \Paymenter\Extensions\Servers\Domains\Data\AccountBinding('namesilo', $binding->serverId, $binding->environment, 'other-account'), $profiles, $exchange); }
    if ($mode === 'registrar') { $protocol = new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($bindingFor(0), $exchange); }
    if ($mode === 'closure') { $acceptance = new \Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($binding, true, true, 25, static fn ($op) => $profiles->hasProof($op),catalogue: $GLOBALS['caps']??null); }
    $fresh = new \Paymenter\Extensions\Servers\Domains\Services\RegistrationPreflight($freshBindings, $features, $acceptance, $mode === 'missing' ? null : $profiles);
    $bridge = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Writer($binding, $features, $acceptance, static function () use (&$engine) { return $engine; }, $protocol);
    $engine = new \Paymenter\Extensions\Servers\Domains\Services\DomainOperations($claims, $fresh, $bridge, $clock);
    $op = $nsNew(placeholderComFixtureName('profile-composition-'.$mode.'-placeholder.com')); $before = $registration->calls;
    commerceReject(fn () => $engine->execute($op->id)); same(null, $op->fresh()->attempt, 'Profile composition refused before claim');
    same($before, $registration->calls, 'Mismatched profile composition makes zero calls');
}
$makeEngine($binding, $exchange); $op = $nsNew(placeholderComFixtureName('missing-servers-placeholder.com')); $before = $registration->calls;
$result = $engine->execute($op->id); same($op->state, $result->state, 'Missing desired setup remains editable before claim');
same(null, $result->attempt, 'Missing desired servers consume no acquisition attempt');
same($op->safe_code, $result->safe_code, 'No unrecoverable hold is invented before owner setup');
same($before, $registration->calls, 'No profile or purchase without desired servers');
$nativeSuccessful=true;echo "Native NameSilo registration scenario passed.\n";
