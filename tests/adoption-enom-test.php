<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/adoption-wire.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestContext, RequestBudget, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{AdoptionReadCommand, AdoptionTransport, Client, ReadCommand};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\EnomAdoptionReader;

$binding = new AccountBinding('enom', 1, Environment::Test, 'synthetic-uid');
$clock = new AdoptionClock; $wire = new AdoptionWire; $gate = new AdoptionTransport($wire);
same(['GetDomainInfo', 'GetDomainExp', 'GetRenew', 'GetExtendInfo'], array_column(AdoptionReadCommand::cases(), 'value'), 'Exact adoption v1 vocabulary');
$refuse = static function (callable $send, string $type, string $message) use ($wire): void {
    $before = count($wire->calls);
    try { $send(); } catch (Throwable $error) {
        same($type, $error::class, 'Exact adoption gate exception');
        same($message, $error->getMessage(), 'Exact adoption gate reason');
        same($before, count($wire->calls), 'Refusal never reaches transport');
        return;
    }
    throw new RuntimeException('Expected adoption gate refusal');
};
// Only valid ReadCommand values reach the allowlist predicate.
foreach (ReadCommand::cases() as $command) {
    if (AdoptionReadCommand::tryFrom($command->value) !== null) { continue; }
    $refuse(fn () => $gate->send(Environment::Test, $command, [], 'synthetic-uid', 'synthetic-secret', 25000),
        DomainException::class, 'Adoption command refused.');
}
foreach ([['Command' => 'SetRenew'], ['SLD' => 'example', 'TLD' => 'com', 'Command' => 'Purchase']] as $parameters) {
    $refuse(fn () => $gate->send(Environment::Test, ReadCommand::Info, $parameters, 'synthetic-uid', 'synthetic-secret', 25000),
        InvalidArgumentException::class, 'Invalid named read parameters.');
}
foreach ([0, 25001] as $timeout) {
    $refuse(fn () => $gate->send(Environment::Test, ReadCommand::Info, ['SLD'=>'example','TLD'=>'com'], 'synthetic-uid', 'synthetic-secret', $timeout),
        DomainException::class, 'Adoption timeout refused.');
}
foreach ([new RuntimeException('Synthetic transport failure'), new DomainException('Synthetic transport refusal')] as $failure) {
    $fake = new AdoptionWire;
    $fake->during = static function () use ($failure): void { throw $failure; };
    same('read_failed', (new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $fake, $clock))->read('example.com', 'com')->facts()['refusal'], 'Transport exception is a failed read');
    same(['GetDomainInfo'], array_column($fake->calls, 0), 'Transport failure stops after exactly one exchange');
}
$reader = new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $wire, $clock);
$facts = $reader->read('example.com', 'com')->facts();
same(null, $facts['refusal'], 'Consistent synthetic fresh read eligible'); same('123', $facts['provider_id'], 'Exact ID proof reused');
same(['GetDomainInfo', 'GetDomainExp', 'GetRenew', 'GetExtendInfo'], array_column($wire->calls, 0), 'No privacy, lock or broad read');
foreach ($wire->calls as $call) { same(25000, $call[3], 'Wire timeout capped'); }
foreach ([
    ['GetDomainInfo', 'synthetic-uid', 'other-uid', 'identity_unconfirmed'],
    ['GetDomainInfo', 'DomainNameID="123"', '', 'identity_unconfirmed'],
    ['GetDomainInfo', 'example.com', 'other-placeholder.com', 'identity_unconfirmed'],
    ['GetDomainExp', '10/19/2027', '10/20/2027', 'expiry_disagrees'],
    ['GetExtendInfo', '10/19/2027', '10/20/2027', 'expiry_disagrees'],
    ['GetDomainExp', '10/19/2027 11:14:00 PM', '10/19/2027', 'expiry_disagrees'],
    ['GetRenew', 'false', 'true', 'auto_renew_on'],
    ['GetRenew', 'false', 'unknown', 'auto_renew_unknown'],
    ['GetExtendInfo', '<RegistrarHold>false', '<RegistrarHold>true', 'state_unconfirmed'],
    ['GetExtendInfo', '<MaxExtension>9', '<MaxExtension>0', 'state_unconfirmed'],
] as [$command, $from, $to, $reason]) {
    $fake = new AdoptionWire; $fake->payloads[$command] = str_replace($from, $to, $fake->payloads[$command]);
    same($reason, (new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $fake, $clock))->read('example.com', 'com')->facts()['refusal'], $reason.' refused');
    same($command === 'GetDomainInfo' ? 1 : 4, count($fake->calls), 'Exact failure read count without retry');
}
foreach (['10/19/2025' => 'expired', '10/19/2037' => 'state_unconfirmed'] as $day => $reason) {
    $fake = new AdoptionWire;
    foreach ($fake->payloads as &$payload) { $payload = str_replace('10/19/2027', $day, $payload); } unset($payload);
    same($reason, (new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $fake, $clock))->read('example.com', 'com')->facts()['refusal'], 'Expired or outside registry horizon refused');
}
$fake = new AdoptionWire;
$fake->payloads['GetDomainInfo'] = str_replace('</Status>', '<Redemption>true</Redemption></Status>', $fake->payloads['GetDomainInfo']);
same('state_unconfirmed', (new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $fake, $clock))->read('example.com', 'com')->facts()['refusal'], 'Redemption refuses despite future expiry');
foreach (['true', 'unknown'] as $redemption) {
    $fake = new AdoptionWire; $fake->payloads['GetExtendInfo'] .= '<Redemption>'.$redemption.'</Redemption>';
    same('state_unconfirmed', (new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $fake, $clock))->read('example.com', 'com')->facts()['refusal'], 'Flat redemption never silently authorizes adoption');
}
$fake = new AdoptionWire;
$fake->payloads['GetExtendInfo'] .= '<GetDomainInfo><Status><Expiration>10/19/2027 1:00:00 AM</Expiration></Status></GetDomainInfo>';
same('expiry_disagrees', (new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $fake, $clock))->read('example.com', 'com')->facts()['refusal'], 'Contradictory nested expiry alias refused');
$fake = new AdoptionWire;
$fake->payloads['GetDomainInfo'] = str_replace('<expiration>', '<expiration utc="2027-10-20T06:14:00Z">', $fake->payloads['GetDomainInfo']);
same('expiry_disagrees', (new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $fake, $clock))->read('example.com', 'com')->facts()['refusal'], 'Unproved UTC attribute cannot reconcile scalar extend date');
$wire = new AdoptionWire; $wire->during = function () use ($clock): void { $clock->time = $clock->time->modify('+24 seconds'); };
same('read_failed', (new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $wire, $clock))->read('example.com', 'com')->facts()['refusal'], 'Whole read cannot overrun 90 seconds');
same([25000, 25000, 25000, 18000], array_column($wire->calls, 3), 'Remaining deadline caps final exchange');
$clock = new AdoptionClock; $wire = new AdoptionWire; $wire->during = function () use ($clock): void { $clock->time = $clock->time->modify('+90 seconds'); };
(new EnomAdoptionReader($binding, 'synthetic-uid', 'synthetic-secret', $wire, $clock))->read('example.com', 'com');
same(1, count($wire->calls), 'Expired deadline stops before second wire');
$clock = new AdoptionClock; $wire = new AdoptionWire; $client = new Client($binding, 'synthetic-uid', 'synthetic-secret', new AdoptionTransport($wire));
$context = new RequestContext($binding, RequestOrigin::Interactive, $clock->now()->modify('+90 seconds'), new RequestBudget(6), $clock);
for ($i = 0; $i < 7; $i++) { try { $client->read(ReadCommand::Info, ['SLD' => 'example', 'TLD' => 'com'], $context); } catch (RuntimeException) {} }
same(6, count($wire->calls), 'Seventh request is refused before wire');
expect(!str_contains(json_encode($facts), 'synthetic-secret'), 'Normalized facts contain no credentials');
echo "Closed adoption read scenarios passed\n";
