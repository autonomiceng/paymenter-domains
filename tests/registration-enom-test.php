<?php
// Adapted from Enom 444f31c dispatch, NS correction and unknown-outcome scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, WriteAcceptance, WriteFeatures};
$binding = $bindingFor(0);
$completionFailures = 0;
\Paymenter\Extensions\Servers\Domains\Models\Operation::updating(static function ($op) use ($registration, &$completionFailures): void {
    if ($registration->mode === 'finishfailure' && isset($op->attempt['execution']['disposition']) && $completionFailures++ === 0) {
        throw new RuntimeException('Synthetic transient completion failure');
    }
});
$exchange = static function ($origin, $command, $parameters, $timeout) use ($registration): string {
    same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'Registration wire outside locks');
    same('https://resellertest.enom.com/interface.asp', $origin, 'Fixed Enom origin'); expect($timeout > 0 && $timeout <= 25000, 'Bounded timeout');
    $registration->calls[] = $command;
    if ($command === 'Purchase') {
        $registration->purchases[] = $parameters;
        same('default', $parameters['UseDNS'], 'Purchase on default DNS'); expect(!isset($parameters['NS1']), 'Follow-up separate');
        same(0, $parameters['RenewName'], 'Provider renewal off'); same('no', $parameters['UseCreditCard'], 'Account funds only');
        expect(isset($parameters['AuxBillingFirstName']), 'Frozen four-role casing');
        if ($registration->mode === 'authority') {
            Illuminate\Support\Facades\DB::table('domains_domains')->where('domain', $parameters['SLD'].'.'.$parameters['TLD'])->update(['authority_generation' => 2]);
        }
        if ($registration->mode === 'unknown') { return '<invalid/>'; }
    }
    if ($command === 'ModifyNS' && in_array($registration->mode, ['lost', 'authority', 'finishfailure'], true)) { throw new RuntimeException('Synthetic lost ancillary response'); }
    $data = $command === 'Purchase' ? '<OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate>' : '';
    if ($command === 'Purchase') {
        if (in_array($registration->mode, ['pending', 'processing', 'queued'], true)) { $data .= '<OrderStatus>'.$registration->mode.'</OrderStatus>'; }
        if ($registration->mode === 'missingid') { $data = '<ExpirationDate>2027-10-18</ExpirationDate>'; }
        if ($registration->mode === 'invalidid') { $data = '<OrderID>0</OrderID><ExpirationDate>2027-10-18</ExpirationDate>'; }
        if ($registration->mode === 'missingexpiry') { $data = '<OrderID>1234</OrderID>'; }
        if ($registration->mode === 'invalidexpiry') { $data = '<OrderID>1234</OrderID><ExpirationDate>2027-02-30</ExpirationDate>'; }
    }
    if ($command === 'GetDNS') { $data = '<dns>ns1.provider.net</dns><dns>'.($registration->mode === 'mismatch' ? 'ns3.provider.net' : 'ns2.provider.net').'</dns>'; }
    $code = $registration->mode === '1300' ? '1300' : '200';
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>'.$code.'</RRPCode>'.$data.'</interface-response>';
};
foreach (['clean', 'lost', 'mismatch', 'unknown', 'authority', 'finishfailure', 'pending', 'processing', 'queued', '1300', 'missingid', 'invalidid', 'missingexpiry', 'invalidexpiry'] as $mode) {
    $registration->mode = $mode; $registration->calls = []; $makeEngine($binding, $exchange);
    $result = $runRegistration($new('enom-'.$mode.'.com'));
    if (in_array($mode, ['unknown', 'pending', 'processing', 'queued', '1300', 'missingid', 'invalidid', 'missingexpiry', 'invalidexpiry'], true)) {
        same('uncertain', $result->state, 'Incomplete purchase stays unresolved'); same(['Purchase'], $registration->calls, 'No follow-ups on pending/unknown');
        same(in_array($mode, ['pending', 'processing', 'queued', '1300'], true) ? 'pending' : 'unknown', $result->attempt['execution']['disposition'], 'Source Purchase classification');
        expect(!isset($result->attempt['registration']), 'No completed acceptance for pending/unknown');
    }
    elseif ($mode === 'authority') {
        same('uncertain', $result->state, 'Changed authority cannot complete');
        same('accepted_with_discrepancy', $result->attempt['execution']['disposition'], 'Authority change retains original accepted execution');
        same('1234', $result->attempt['evidence']['registration']['orderReference'], 'Original acceptance facts survive authority change');
        same(['nameservers'], array_keys($result->attempt['corrections']), 'Authority change retains correction requirements');
        same('registration_authority_changed', $result->safe_code, 'Specific completion authority refusal');
    }
    else { $assertRetained($result, $mode === 'clean' ? [] : ['nameservers']); same('1234', $result->attempt['registration']['order_reference'], 'Original order retained'); }
    $nativeBeforeReplay = nativeFinancialSnapshot($result);
    $before = $registration->calls; $engine->execute($result->id); same($before, $registration->calls, 'No replay of accepted/unknown attempt');
    same($nativeBeforeReplay, nativeFinancialSnapshot($result), 'Replay preserves native/financial state');
}
expect($completionFailures >= 2, 'Transient completion retried with original acceptance and correction evidence');
same(['NS1' => 'ns1.child.com', 'NS2' => 'ns2.child.com'], \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers(['NS1.Child.com.', 'ns2.child.com']), 'Enom source accepts normalized child hosts');
commerceReject(fn () => \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers(['ns1.provider.net', 'NS1.provider.net.']));
// Protected scope is shared by sibling subclasses: concrete guard must reject before the first port call.
$attacker = new class extends RegistrationTransport {
    public function attack($target, $op, $ctx): DriverResult { return $target->sendRegistration($op, $ctx, [], 'https://resellertest.enom.com/interface.asp', 25000); }
    protected function sendRegistration(AdmittedOperation $o, RequestContext $c, array $p, string $s, int $t): DriverResult { throw new LogicException; }
};
$forged = new AdmittedOperation($binding, new Query('forged.com', Action::Register, 1), 'forged', 'claim', 1, 'funding', str_repeat('a', 64), new Money('1.00', 'USD'));
$ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+25 seconds'), new RequestBudget(10), $clock);
$before = $registration->calls; commerceReject(fn () => $attacker->attack($protocol, $forged, $ctx)); same($before, $registration->calls, 'Sibling forged descriptor has zero wire calls');
// Gate identity is mandatory before claim, not just equal gate values.
$makeEngine($binding, $exchange); $op = $new('composition.com');
commerceReject(fn () => $bridge->requireComposition($fresh, new WriteFeatures(registration: true), $acceptance, $protocol));
commerceReject(fn () => $bridge->requireComposition($fresh, new WriteFeatures(registration: true), new WriteAcceptance($binding, true, true, 25), $protocol));
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
$before = $registration->calls; $result = $runRegistration($new('rogue-writer.com'));
same('uncertain', $result->state, 'Refused production composition stays sealed'); same($before, $registration->calls, 'Nonbridge writer sends zero requests');
// One real bridge Purchase requests three years; both response and later owned readback prove only one.
require __DIR__.'/Support/reconciliation-fixture.php';
$registration->mode = 'clean'; $registration->calls = []; $registration->purchases = []; $makeEngine($binding, $exchange);
$op = $new('three-year-short-readback.com', term: 3); $money = $nativeMoney();
$result = $runRegistration($op);
same([3], array_column($registration->purchases, 'NumYears'), 'M8 exactly one Purchase requests the paid three-year term');
same(['pending', 'registration_evidence_required', '2027-10-18'],
    [$result->state, $result->safe_code, $result->attempt['registration']['response_expiry']], 'M8 short Purchase remains financially unresolved');
$observation->name = $op->domain->domain; $observation->years = 3; $observation->expiryYears = 1; $observation->reference = '1234';
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
echo "Enom registration, correction, guard and policy composition checks passed\n"; $nativeSuccessful = true;
