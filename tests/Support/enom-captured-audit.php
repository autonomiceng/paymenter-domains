<?php
// Synthetic captured replies exercise the public normalizers, not just XML parsing.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, Environment, Query, RenewalStatus, RequestBudget, RequestContext, RequestOrigin, Support};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, ResponseParser, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{AccountVerifier, Driver, ManagementRead};

(function (): void {
    $fixtures = require __DIR__.'/enom-captured-reads.php';
    $fixtures += require __DIR__.'/enom-captured-renewal.php';
    $parser = new ResponseParser; $parsed = [];
    foreach ($fixtures as $case => $xml) {
        $command = constant(ReadCommand::class.'::'.$case);
        $parsed[$case] = $parser->parse($command, $xml);
        same('success', $parsed[$case]->status, 'Captured '.$case.' envelope accepted');
    }
    $binding = new AccountBinding('enom', 1, Environment::Test, 'synthetic-uid');
    $clock = new class implements Clock {
        public DateTimeImmutable $time;
        public function __construct() { $this->time = new DateTimeImmutable('2026-10-22T12:00:00Z'); }
        public function now(): DateTimeImmutable { return $this->time; }
    };
    $wire = new class($fixtures) implements Transport {
        public function __construct(private array $fixtures) {}
        public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
            #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
        { return $this->fixtures[$command->name] ?? throw new LogicException('Uncaptured command requested.'); }
    };
    $driver = new Driver(new Client($binding, 'synthetic-uid', 'synthetic-token', $wire), ['com']);
    $context = fn () => new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+1 hour'), new RequestBudget(20), $clock);
    $domain = $driver->readDomain('example.com', $context())->evidence()->domain;
    same(true, $domain?->owned, 'Captured attributed owner text authenticated');
    same('456', $domain?->providerReference, 'Captured attributed domain ID retained');
    same('2027-10-22T13:53:00+00:00', $domain?->expiry?->value, 'Captured status expiry uses Pacific source zone');
    same(false, $domain?->providerAutoRenew, 'Captured flat auto-renew zero');
    same(true, $domain?->locked, 'Captured hyphenated reg-lock one');
    same(false, $domain?->privacy, 'Captured wrapped privacy flags');
    same($domain->expiry->value, $driver->expiry('example.com', $context())->facts()['expiry']->value, 'Captured flat expirationdate agrees');
    $contacts = $driver->contacts('example.com', $context())->facts();
    same('Alex', $contacts['contacts']['registrant']['first_name'], 'Captured role-prefixed contact field');
    same('person@example.com', $contacts['contacts']['registrant']['email_address'], 'Captured contact email key');
    same(['registrant' => true, 'admin' => false, 'tech' => false, 'auxbilling' => false], $contacts['collected'], 'Captured contact-match casing retained');
    same(false, $contacts['pending_verification'], 'Captured verification False');
    same(false, $contacts['transfer_locked'], 'Captured transfer lock False');
    expect(!array_key_exists('billing', $contacts['contacts']), 'Captured account billing is not a domain role');
    $renewal = $driver->renewalInfo('example.com', $context())->facts();
    same(9, $renewal['max_extension'], 'Captured flat renewal maximum');
    same(false, $renewal['registrar_hold'], 'Captured flat registrar hold');
    same('2027-10-22', $renewal['expiry']->value, 'Captured flat calendar expiration');
    same('12345.67', $driver->balance($context())->evidence()->balance?->amount, 'Captured grouped available balance differs from total');
    same('synthetic-uid', (new AccountVerifier(Environment::Test, $wire))->verify(Environment::Test,
        ['uid' => 'synthetic-uid', 'token' => 'synthetic-token']), 'Captured balance authenticates exact submitted account');
    same('8.95', $driver->productPrice('com', 'register', 1, $context())->facts()['price'], 'Captured enabled product annual price');
    $tld = $driver->tldCapabilities('com', $context())->facts();
    same(range(1, 10), $tld['capabilities']->get('register')->terms, 'Captured singleton TLD Year terms');
    foreach (['lock', 'privacy', 'ds'] as $feature) { same(Support::Supported, $tld['capabilities']->get($feature)->support, 'Captured TLD '.$feature); }
    same(false, $tld['requires_nameservers'], 'Captured DNSRequired False');
    same(false, $tld['registry_attributes'], 'Captured ExtAttributes False');
    same(false, $tld['auto_renew_only'], 'Captured AutoRenewOnly False');
    same(true, $tld['restorable'], 'Captured restorable is capability only');
    foreach ([Action::Register, Action::Transfer] as $action) {
        $availability = $driver->availability(new Query('example.com', $action, 1), $context())->evidence();
        same($action === Action::Transfer, $availability->available, 'Captured Check 211 and Transferable True');
        same(false, $availability->premium, 'Captured three False premium flags');
    }
    $nameservers = ManagementRead::nameservers($parsed['Nameservers']);
    same(array_map(fn ($n) => 'dns'.$n.'.name-services.com', range(1, 5)), $nameservers, 'Captured repeated flat dns scalars');
    same(true, ManagementRead::defaultDns($nameservers), 'Captured default DNS derives from returned names');
    $hosts = ManagementRead::records($parsed['Hosts'], 'dns');
    same(1, count($hosts), 'Captured singleton host normalized');
    same('*', $hosts[0]['name'], 'Captured wildcard host retained');
    same('A', $hosts[0]['type'], 'Captured host type');
    same('192.0.2.1', $hosts[0]['address'], 'Captured host address shape');
    same([], ManagementRead::records($parsed['Srv'], 'srv'), 'Captured empty srv-records is known empty');
    same([], ManagementRead::records($parsed['Ds'], 'ds'), 'Captured zero DNSSEC with nested result is known empty');
    $clock->time = new DateTimeImmutable('2027-10-23T12:00:00Z');
    same(RenewalStatus::Unknown, $driver->renewalAssessment(new Query('example.com', Action::Renew, 1), $context())->evidence()->renewal,
        'Captured empty expired inventory cannot prove this domain grace eligible');
    $privacy = ManagementRead::privacy($parsed['Privacy']);
    same(['allowed' => true, 'exists' => false, 'enabled' => false, 'autorenew' => null, 'expiry' => null, 'cost' => '6.0000'], $privacy,
        'Captured dollar-prefixed WPPS annual cost, absent subscription and empty dates');
    foreach (['$$6.00', '$-6.00', '$ 6.00', '$6.00001', '$6e0', '$6,000.00', 'EUR6.00', ''] as $bad) {
        $reply = $parser->parse(ReadCommand::Privacy, str_replace('$6.00', $bad, $fixtures['Privacy']));
        same(null, ManagementRead::privacy($reply)['cost'], 'Malformed privacy cost never becomes zero or known');
    }
    echo "Captured Enom read shapes: all normalized paths and scalar formats passed.\n";
})();
