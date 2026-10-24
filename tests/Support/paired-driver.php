<?php
// Synthetic paired acquisition lifecycle; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/transfer-fixture.php';
require __DIR__.'/lifecycle-scenarios.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, RegistrationPreflight, WriteFeatures};

Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema::install();
Paymenter\Extensions\Servers\Domains\Services\HoldingAdoptionSchema::install();

$scenarioNew = static function (int $i, string $action, string $name, bool $paid = true) use ($newTransfer, $user, $native, $tlds, $sellers, $clock, $state) {
    if ($action === 'transfer') { return $newTransfer($name, $i, $paid); }
    scenarioSwitch($sellers, $clock, $tlds[$i]->server_id);
    $cart = commerceCart($user); commerceAdd($cart, $native->product($tlds[$i], 'register'), $name);
    commerceCheckout($user, $cart);
    $op = Domain::where('domain', strtolower(rtrim($name, '.')))->sole()->operations()->sole();
    $op->update(['payload' => [...$op->payload, 'nameservers' => ['ns1.provider.example.net', 'ns2.provider.example.net'], 'privacy' => false]]);
    $state->query = Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op, $op->domain);
    return $paid ? scenarioFund($op) : $op->fresh();
};
$registrationExchange = static function ($origin, $command, $p, $timeout) use ($state, $exchange): string {
    same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'Every exchange outside locks');
    expect($timeout > 0 && $timeout <= 25000, 'Every exchange bounded');
    if (in_array($command, ['contactAdd', 'contactList'], true)) {
        // Reuse the transfer fixture's independently checked fresh-profile exchange.
        return $exchange($origin, $command, $p, $timeout);
    }
    $state->calls[] = $command;
    if ($state->duringWrite) { ($state->duringWrite)($command); }
    if ($state->mode === 'lost') { throw new RuntimeException('Synthetic response loss'); }
    if (in_array($command, ['Purchase', 'ModifyNS', 'GetDNS'], true)) {
        same('https://resellertest.enom.com/interface.asp', $origin, 'Enom fixed origin');
        if ($command === 'Purchase') { same(0, $p['RenewName'], 'Enom renewal off'); same('no', $p['UseCreditCard'], 'No card'); }
        $data = match ($command) {
            'Purchase' => '<OrderID>9001</OrderID><ExpirationDate>2027-10-18</ExpirationDate>',
            'GetDNS' => '<dns>ns1.provider.example.net</dns><dns>ns2.provider.example.net</dns>', default => '',
        };
        return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode>'.$data.'</interface-response>';
    }
    same('https://ote.namesilo.com/apibatch', $origin, 'NameSilo fixed batch origin');
    $reply = ['code' => 300, 'detail' => 'synthetic'];
    if ($command === 'registerDomain') {
        same(0, $p['auto_renew'], 'NameSilo renewal off'); same(0, $p['private'], 'Explicit privacy');
        same((string) $state->id, $p['contact_id'], 'Fresh non-default contact profile');
        $reply += ['domain' => $p['domain'], 'order_amount' => '99.00'];
    } else {
        same('getDomainInfo', $command, 'Only named registration readback');
        $reply += ['created' => '2026-10-18', 'expires' => '2027-10-18', 'status' => 'Active', 'locked' => 'Yes', 'private' => 'No', 'auto_renew' => 'No',
            'nameservers' => [['nameserver' => 'ns1.provider.example.net'], ['nameserver' => 'ns2.provider.example.net']],
            'contact_ids' => array_fill_keys(['registrant', 'administrative', 'technical', 'billing'], (string) $state->id)];
    }
    return json_encode(['request' => ['operation' => $command], 'reply' => $reply], JSON_THROW_ON_ERROR);
};
$scenarioCompose = static function (int $i, string $action, bool $enabled = true) use ($makeTransfer, $registrationExchange, $freshBindings, $claims, $clock, $state,
    &$engine, &$bridge, &$protocol, &$fresh, &$accepted, &$profiles, &$binding): void {
    $state->duringWrite = null;
    $makeTransfer($i, new WriteFeatures(registration: $action === 'register' && $enabled, transfer: $action === 'transfer' && $enabled));
    if ($action === 'transfer') { return; }
    $features = new WriteFeatures(registration: $enabled);
    $profiles = new Paymenter\Extensions\Servers\Domains\Services\RegistrationProfiles($binding, $claims, $clock, $registrationExchange);
    $accepted = new Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($binding, true, true, 25, $profiles);
    $fresh = new RegistrationPreflight($freshBindings, $features, $accepted, $profiles);
    $class = 'Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($i === 0 ? 'Enom' : 'NameSilo').'\\';
    $protocol = $i === 0 ? new ($class.'Registration')($binding, $registrationExchange) : new ($class.'Registration')($binding, $profiles, $registrationExchange);
    $bridge = new ($class.'Writer')($binding, $features, $accepted, static function () use (&$engine) { return $engine; }, $protocol);
    $engine = new DomainOperations($claims, $fresh, $bridge, $clock);
    Cache::forget('domains-write-slot:'.$binding->fingerprint);
};
$scenarioSends = static fn (): array => array_values(array_filter($state->calls, static fn ($c) => in_array($c, ['Purchase', 'registerDomain', 'TP_CreateOrder', 'transferDomain'], true)));
