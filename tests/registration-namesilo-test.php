<?php
// Synthetic acceptance/profile/correction contract; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-registration-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
foreach (['300', '301', '302', 'amount', 'badns', 'mismatch', 'remove', 'stillon', 'lost', 'unknown', 'default', 'wrongcontact', 'authoritydrift', 'hyphenphone', 'wrongphone', 'missingphone'] as $mode) {
    $registration->mode = $mode; $registration->calls = []; $registration->diagnostics = []; $makeEngine($binding, $exchange);
    $result = $runRegistration($nsNew('ns-'.$mode.'.com'));
    if (in_array($mode, ['default', 'wrongcontact', 'authoritydrift', 'wrongphone', 'missingphone'], true)) {
        if ($mode === 'authoritydrift') {
            same(['authority_before' => true, 'authority_after' => false], $registration->diagnostics, 'Actual original claim snapshot detects policy drift');
        }
        same('held', $result->state, 'Invalid profile proof holds'); same('absent', $result->attempt['seal'], 'No purchase seal on invalid proof');
        same(['contactAdd', 'contactList'], $registration->calls, 'Default/mismatched profile never reaches purchase'); continue;
    }
    if ($mode === 'unknown') {
        same('uncertain', $result->state, 'Unknown purchase stays unresolved');
        same(['contactAdd', 'contactList', 'registerDomain'], $registration->calls, 'No follow-up/retry after unknown');
    } else {
        $corrections = match ($mode) { '301' => ['nameservers'], '302' => ['contacts'], 'badns', 'mismatch' => ['nameservers'], 'stillon' => ['auto_renew'],
            'lost' => ['auto_renew', 'nameservers', 'contacts', 'privacy'], default => [] };
        $assertRetained($result, $corrections);
        same(null, $result->attempt['registration']['order_reference'], 'No fabricated NameSilo order ID');
        same($mode === 'amount' ? null : '99.00', $result->attempt['charge']['amount'] ?? null, 'Charge observation retained without accepting actual cost');
        same('99.00', $result->attempt['authorized_cost']['amount'], 'No retail cost floor at dispatch');
    }
    if (in_array($mode, ['remove', 'stillon'], true)) {
        same(['contactAdd', 'contactList', 'registerDomain', 'getDomainInfo', 'removeAutoRenewal', 'getDomainInfo'], $registration->calls, 'Removal followed by fresh readback');
    }
    $nativeBeforeReplay = nativeFinancialSnapshot($result);
    $before = $registration->calls; $engine->execute($result->id); same($before, $registration->calls, 'No replay/refund/fallback');
    same($nativeBeforeReplay, nativeFinancialSnapshot($result), 'Replay preserves native/financial state');
    expect($profiles->hasProof($result), 'Exact proof retained for current payload');
    $foreign = clone $result; $foreign->account_fingerprint = str_repeat('f', 64); expect(!$profiles->hasProof($foreign), 'Proof account isolated');
    $changed = clone $result; $changed->payload = $result->payload + ['changed' => true]; expect(!$profiles->hasProof($changed), 'Proof payload isolated');
    $otherOwner = clone $result; $otherDomain = clone $result->domain; $otherDomain->user_id = $user->id + 999; $otherOwner->setRelation('domain', $otherDomain);
    expect(!$profiles->hasProof($otherOwner), 'Proof owner isolated');
    $clock->instant = $clock->now()->modify('+121 seconds'); expect(!$profiles->hasProof($result), 'Stale profile proof refuses'); $clock->instant = $clock->now()->modify('-121 seconds');
}
$attacker = new class extends RegistrationTransport {
    public function attack($target, $op, $ctx): DriverResult { return $target->sendRegistration($op, $ctx, [], 'https://ote.namesilo.com/apibatch', 25000); }
    protected function sendRegistration(AdmittedOperation $o, RequestContext $c, array $p, string $s, int $t): DriverResult { throw new LogicException; }
};
$forged = new AdmittedOperation($binding, new Query('forged.com', Action::Register, 1), 'forged', 'claim', 1, 'funding', str_repeat('a', 64), new Money('1.00', 'USD'));
$ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+25 seconds'), new RequestBudget(10), $clock);
$before = $registration->calls; commerceReject(fn () => $attacker->attack($protocol, $forged, $ctx)); same($before, $registration->calls, 'NameSilo sibling guard makes zero calls');
foreach (['different', 'missing', 'closure', 'account', 'registrar'] as $mode) {
    $features = new \Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(registration: true); $makeEngine($binding, $exchange, $features);
    $other = new \Paymenter\Extensions\Servers\Domains\Services\RegistrationProfiles($binding, $claims, $clock, $exchange);
    if ($mode === 'different') { $protocol = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration($binding, $other, $exchange); }
    if ($mode === 'account') { $protocol = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration(new \Paymenter\Extensions\Servers\Domains\Data\AccountBinding('namesilo', $binding->serverId, $binding->environment, 'other-account'), $profiles, $exchange); }
    if ($mode === 'registrar') { $protocol = new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($bindingFor(0), $exchange); }
    if ($mode === 'closure') { $acceptance = new \Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($binding, true, true, 25, static fn ($op) => $profiles->hasProof($op)); }
    $fresh = new \Paymenter\Extensions\Servers\Domains\Services\RegistrationPreflight($freshBindings, $features, $acceptance, $mode === 'missing' ? null : $profiles);
    $bridge = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Writer($binding, $features, $acceptance, static function () use (&$engine) { return $engine; }, $protocol);
    $engine = new \Paymenter\Extensions\Servers\Domains\Services\DomainOperations($claims, $fresh, $bridge, $clock);
    $op = $nsNew('profile-composition-'.$mode.'.com'); $before = $registration->calls;
    commerceReject(fn () => $engine->execute($op->id)); same(null, $op->fresh()->attempt, 'Profile composition refused before claim');
    same($before, $registration->calls, 'Mismatched profile composition makes zero calls');
}
$makeEngine($binding, $exchange); $op = $nsNew('missing-servers.com'); $before = $registration->calls;
$result = $engine->execute($op->id); same($op->state, $result->state, 'Missing desired setup remains editable before claim');
same(null, $result->attempt, 'Missing desired servers consume no acquisition attempt');
same($op->safe_code, $result->safe_code, 'No unrecoverable hold is invented before owner setup');
same($before, $registration->calls, 'No profile or purchase without desired servers');
require __DIR__.'/Support/namesilo-profile-authority.php';
require __DIR__.'/Support/namesilo-nexus.php';
echo "NameSilo fresh profile, acceptance and ancillary correction checks passed\n"; $nativeSuccessful = true;
