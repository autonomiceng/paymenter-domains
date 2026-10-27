<?php
// Adapted from Enom 444f31c dispatch, NS correction and unknown-outcome scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/registration-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/owner-pages.php';
storefrontViews();
$createOperation = $new;
$new = static function (string $name, bool $paid = true, bool $setup = true, string $action = 'register', int $term = 1) use ($createOperation) {
    return $createOperation(reservedFixtureName($name), $paid, $setup, $action, $term);
};
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, NameserverEvidence, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, DomainSummaries, WriteAcceptance, WriteFeatures};
$ownerPage = static function ($result) use ($user, $clock): string {
    $summary = (new DomainSummaries)->one($user, 'managed', (int) $result->domain->id, $clock->now()->getTimestamp());
    return view('domains::partials.summary', ['summary' => $summary, 'summaryOnManaged' => true])->render();
};
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
    if ($command === 'GetDNS' && in_array($registration->mode, ['lost', 'authority'], true)) { throw new RuntimeException('Synthetic nameserver readback unavailable'); }
    $data = $command === 'Purchase' ? '<OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate>' : '';
    if ($command === 'Purchase') {
        if (in_array($registration->mode, ['pending', 'processing', 'queued'], true)) { $data .= '<OrderStatus>'.$registration->mode.'</OrderStatus>'; }
        if ($registration->mode === 'missingid') { $data = '<ExpirationDate>2027-10-18</ExpirationDate>'; }
        if ($registration->mode === 'invalidid') { $data = '<OrderID>0</OrderID><ExpirationDate>2027-10-18</ExpirationDate>'; }
        if ($registration->mode === 'missingexpiry') { $data = '<OrderID>1234</OrderID>'; }
        if ($registration->mode === 'invalidexpiry') { $data = '<OrderID>1234</OrderID><ExpirationDate>2027-02-30</ExpirationDate>'; }
    }
    if ($command === 'GetDNS') { $data = '<dns>ns1.provider.example</dns><dns>'.($registration->mode === 'mismatch' ? 'ns3.provider.example' : 'ns2.provider.example').'</dns>'; }
    $code = $registration->mode === '1300' ? '1300' : '200';
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>'.$code.'</RRPCode>'.$data.'</interface-response>';
};
foreach (['clean', 'defaultdns', 'lost', 'mismatch', 'unknown', 'authority', 'finishfailure', 'pending', 'processing', 'queued', '1300', 'missingid', 'invalidid', 'missingexpiry', 'invalidexpiry'] as $mode) {
    $registration->mode = $mode; $registration->calls = []; $makeEngine($binding, $exchange);
    $operation = $new('enom-'.$mode.'.example.com');
    if ($mode === 'defaultdns') { $operation->update(['payload' => [...$operation->payload, 'nameservers' => []]]); }
    $result = $runRegistration($operation);
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
        same(null, $result->domain->fresh()->observation['nameservers'] ?? null, 'Nameserver facts are not saved after authority changes');
        same('registration_authority_changed', $result->safe_code, 'Specific completion authority refusal');
    }
    else {
        $assertRetained($result, in_array($mode, ['clean', 'defaultdns', 'finishfailure'], true) ? [] : ['nameservers']); same('1234', $result->attempt['registration']['order_reference'], 'Original order retained');
        $fact = $result->domain->fresh()->observation['nameservers'] ?? null;
        if (in_array($mode, ['clean', 'finishfailure'], true)) {
            same(['ns1.provider.example', 'ns2.provider.example'], $fact['nameservers'] ?? null, 'GetDNS values are stored as observed');
            same('observed', $fact['state'] ?? null, 'GetDNS provenance is explicit');
            if ($mode === 'clean') {
                $page = $ownerPage($result);
                expect(str_contains($page, '<dt>Nameservers</dt><dd><span class="domains-wrap">ns1.provider.example, ns2.provider.example</span>')
                    && !str_contains($page, 'Shows after the first check'), 'Enom registration nameservers reach the owner detail page');
            }
        } elseif ($mode === 'lost') {
            same(['ns1.provider.example', 'ns2.provider.example'], $fact['nameservers'] ?? null, 'Submitted values are retained when GetDNS is unavailable');
            same('requested', $fact['state'] ?? null, 'Missing GetDNS is clearly unconfirmed');
        } elseif ($mode === 'mismatch') {
            same(['ns1.provider.example', 'ns3.provider.example'], $fact['nameservers'] ?? null, 'A mismatched GetDNS result remains the observed provider value');
            same('observed', $fact['state'] ?? null, 'Mismatch remains an observed result, not a requested value');
        }
        if ($mode === 'defaultdns') {
            same(null, $fact, 'Purchase has no nameserver values and does not add a registration-path read');
            same(['Purchase'], $registration->calls, 'Default-DNS registration preserves the single-send request sequence');
        }
    }
    $nativeBeforeReplay = nativeFinancialSnapshot($result);
    $before = $registration->calls; $engine->execute($result->id); same($before, $registration->calls, 'No replay of accepted/unknown attempt');
    same($nativeBeforeReplay, nativeFinancialSnapshot($result), 'Replay preserves native/financial state');
}
commerceReject(fn () => NameserverEvidence::observed(['ns1.provider.example'], 'registration_request', $clock->now()));
commerceReject(fn () => NameserverEvidence::requested(['ns1.provider.example'], 'enom_getdns', $clock->now()));
commerceReject(fn () => NameserverEvidence::observed(['ns1.provider.example', 'ns1.provider.example'], 'enom_getdns', $clock->now()));
commerceReject(fn () => NameserverEvidence::observed(['invalid nameserver'], 'enom_getdns', $clock->now()));
same(null, NameserverEvidence::fromArray(['nameservers' => ['ns1.provider.example'], 'state' => 'observed', 'source' => 'enom_getdns', 'at' => 'not-a-time']), 'Malformed stored nameserver timestamps are ignored');
expect($completionFailures >= 2, 'Transient completion retried with original acceptance and correction evidence');
same(['NS1' => 'ns1.child.example', 'NS2' => 'ns2.child.example'], \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers(['NS1.Child.example.', 'ns2.child.example']), 'Enom source accepts normalized child hosts');
commerceReject(fn () => \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers(['ns1.provider.example', 'NS1.provider.example.']));
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
$before = $registration->calls; $result = $runRegistration($new('rogue-writer.example.com'));
same('uncertain', $result->state, 'Refused production composition stays sealed'); same($before, $registration->calls, 'Nonbridge writer sends zero requests');
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
echo "Enom registration, correction, guard and policy composition checks passed\n"; $nativeSuccessful = true;
