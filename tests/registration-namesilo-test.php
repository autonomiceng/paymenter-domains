<?php
// Synthetic acceptance/profile/correction contract; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $clock->now()); $nsProduct = $native->product($tlds[1], 'register');
$nsNew = static function ($name) use ($nsProduct, $user) {
    $cart = commerceCart($user); commerceAdd($cart, $nsProduct, $name); commerceCheckout($user, $cart);
    $op = Domain::where('domain', $name)->sole()->operations()->sole();
    Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
    App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']); return $op;
};
$binding = $bindingFor(1);
$exchange = static function ($origin, $command, $parameters, $timeout) use ($registration): string {
    same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'All profile/purchase/follow-up work outside locks');
    same('https://sandbox.namesilo.com/apibatch', $origin, 'Fixed batch route'); expect($timeout > 0 && $timeout <= 25000, 'Bounded request');
    $registration->calls[] = $command; $reply = ['code' => 300, 'detail' => 'synthetic'];
    if ($command === 'contactAdd') { $registration->profile = $parameters; $reply['contact_id'] = (string) ++$registration->id; }
    elseif ($command === 'contactList') {
        $fields = ['first_name', 'last_name', 'company', 'address', 'address2', 'city', 'state', 'zip', 'country', 'email', 'phone'];
        $row = array_combine($fields, array_values($registration->profile));
        $row += ['contact_id' => (string) $registration->id, 'default_profile' => $registration->mode === 'default' ? '1' : '0'];
        if ($registration->mode === 'wrongcontact') { $row['email'] = 'other@example.com'; }
        $reply['contact'] = $row;
    } elseif ($command === 'registerDomain') {
        same((string) $registration->id, $parameters['contact_id'], 'Fresh proven profile only');
        same(0, $parameters['auto_renew'], 'Purchase disables auto-renew'); same(0, $parameters['private'], 'Frozen privacy policy');
        if ($registration->mode === 'unknown') { return '{}'; }
        $reply += ['domain' => $parameters['domain'], 'order_amount' => $registration->mode === 'amount' ? '1000000000000000000.00' : '99.00'];
        $reply['code'] = in_array($registration->mode, ['301', '302'], true) ? (int) $registration->mode : 300;
    } elseif ($command === 'getDomainInfo') {
        if ($registration->mode === 'lost') { throw new RuntimeException('Synthetic readback loss'); }
        $removed = in_array('removeAutoRenewal', $registration->calls, true);
        $reply += ['created' => '2026-10-18', 'expires' => '2027-10-18', 'status' => 'Active', 'locked' => 'Yes', 'private' => 'No',
            'auto_renew' => $registration->mode === 'stillon' || ($registration->mode === 'remove' && !$removed) ? 'Yes' : 'No',
            'nameservers' => [['nameserver' => 'ns2.provider.net'], ['nameserver' => 'ns1.provider.net']],
            'contact_ids' => array_fill_keys(['billing', 'technical', 'registrant', 'administrative'], (string) $registration->id)];
        if ($registration->mode === 'badns') { $reply['nameservers'] = ['nameserver' => 'invalid']; }
    } elseif ($command !== 'removeAutoRenewal') { throw new LogicException('Unexpected protocol command'); }
    return json_encode(['request' => ['operation' => $command], 'reply' => $reply], JSON_THROW_ON_ERROR);
};
foreach (['300', '301', '302', 'amount', 'badns', 'remove', 'stillon', 'lost', 'unknown', 'default', 'wrongcontact'] as $mode) {
    $registration->mode = $mode; $registration->calls = []; $makeEngine($binding, $exchange);
    $result = $runRegistration($nsNew('ns-'.$mode.'.com'));
    if (in_array($mode, ['default', 'wrongcontact'], true)) {
        same('held', $result->state, 'Invalid profile proof holds'); same('absent', $result->attempt['seal'], 'No purchase seal on invalid proof');
        same(['contactAdd', 'contactList'], $registration->calls, 'Default/mismatched profile never reaches purchase'); continue;
    }
    if ($mode === 'unknown') {
        same('uncertain', $result->state, 'Unknown purchase stays unresolved');
        same(['contactAdd', 'contactList', 'registerDomain'], $registration->calls, 'No follow-up/retry after unknown');
    } else {
        $corrections = match ($mode) { '301' => ['nameservers'], '302' => ['contacts'], 'badns' => ['nameservers'], 'stillon' => ['auto_renew'],
            'lost' => ['auto_renew', 'nameservers', 'contacts', 'privacy'], default => [] };
        $assertRetained($result, $corrections);
        same(null, $result->attempt['registration']['order_reference'], 'No fabricated NameSilo order ID');
        same($mode === 'amount' ? null : '99.00', $result->attempt['charge']['amount'] ?? null, 'Charge observation retained without accepting actual cost');
        same('99.00', $result->attempt['authorized_cost']['amount'], 'No retail cost floor at dispatch');
    }
    if (in_array($mode, ['remove', 'stillon'], true)) {
        same(['contactAdd', 'contactList', 'registerDomain', 'getDomainInfo', 'removeAutoRenewal', 'getDomainInfo'], $registration->calls, 'Removal followed by fresh readback');
    }
    $before = $registration->calls; $engine->execute($result->id); same($before, $registration->calls, 'No replay/refund/fallback');
    expect($profiles->hasProof($result), 'Exact proof retained for current payload');
    $foreign = clone $result; $foreign->account_fingerprint = str_repeat('f', 64); expect(!$profiles->hasProof($foreign), 'Proof account isolated');
    $changed = clone $result; $changed->payload = $result->payload + ['changed' => true]; expect(!$profiles->hasProof($changed), 'Proof payload isolated');
    $otherOwner = clone $result; $otherDomain = clone $result->domain; $otherDomain->user_id = $user->id + 999; $otherOwner->setRelation('domain', $otherDomain);
    expect(!$profiles->hasProof($otherOwner), 'Proof owner isolated');
    $clock->instant = $clock->now()->modify('+121 seconds'); expect(!$profiles->hasProof($result), 'Stale profile proof refuses'); $clock->instant = $clock->now()->modify('-121 seconds');
}
$attacker = new class extends RegistrationTransport {
    public function attack($target, $op, $ctx): DriverResult { return $target->sendRegistration($op, $ctx, [], 'https://sandbox.namesilo.com/apibatch', 25000); }
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
echo "NameSilo fresh profile, acceptance and ancillary correction checks passed\n"; $nativeSuccessful = true;
