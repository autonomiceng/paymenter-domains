<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/accounts.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, Environment, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Price, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema, PriceCatalogue, PricingRule, PricingRules};
FoundationSchema::install(); $account = fixtureAccount(1, 'enom', 'offline');
Tld::create([...$account, 'tld' => 'com', 'enabled' => false, 'renew_enabled' => true]);
$clock = new class implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-18T12:00:00Z'); } };
$binding = new AccountBinding('enom', 1, Environment::Test, 'offline');
$context = fn () => new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+1 hour'), new RequestBudget(5), $clock);
$wire = new class implements Transport {
    public string $price = '8.9550'; public bool $enabled = true; public bool $fail = false;
    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        if ($this->fail) { throw new RuntimeException('offline timeout'); }
        if ($command === ReadCommand::TldInfo) { return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done><TLDs><TLD><TLD>com</TLD><Registration><Minimum>1</Minimum><Maximum>3</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs></interface-response>'; }
        if ($command === ReadCommand::Attributes) { return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done><ResponseCount>0</ResponseCount></interface-response>'; }
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done><ProductPrice><ProductEnabled>'.($this->enabled ? 'true' : 'false').'</ProductEnabled><Price>'.$this->price.'</Price></ProductPrice></interface-response>';
    }
};
$driver = new Driver(new Client($binding, 'offline', 'synthetic', $wire), ['com']);
$rules = new PricingRules; $catalogue = new PriceCatalogue($rules); $query = new Query('example.com', Action::Register, 3);
$accepted = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance($binding, 1, ['com' => ['register' => [3], 'renew' => [1], 'transfer' => [], 'registry' => true, 'renewal_safe' => true]]);
$read = fn () => $driver->catalogueBatch($accepted, new \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness(86400, 86400), $context())['com']?->rates ?? throw new DomainException;

same(['synced' => 1, 'failed' => []], $catalogue->sync(1, $read), 'Real Enom adapter feeds neutral pricing service');
$snapshot = $catalogue->current(1, $account['account_fingerprint'], 'com', 'register', 3, 'USD', $clock->now());
same('26.86500000', Price::where('action', 'register')->firstOrFail()->wholesale, 'Database zero padding alone is normalized by catalogue');
same('26.8650', $snapshot->wholesale, 'PriceSnapshot preserves exact term wholesale');
same('34.92', $snapshot->retail, 'Annual retail rounds before selected term multiplication');
same('enom_product_price', $snapshot->source, 'Snapshot retains adapter provenance');
same('2026-10-20T12:00:00+00:00', $snapshot->freshUntil->format(DATE_ATOM), 'Freshness survives database persistence');
$before = Price::orderBy('id')->get()->toJson();
$wire->fail = true; same(0, $catalogue->sync(1, $read)['synced'], 'Failed read never zero or withdrawal');
same($before, Price::orderBy('id')->get()->toJson(), 'Timeout retains all price bytes, revisions and freshness');
$wire->fail = false; $wire->price = '8.95500000'; same(0, $catalogue->sync(1, $read)['synced'], 'Unsupported precision rejects whole TLD refresh');
same($before, Price::orderBy('id')->get()->toJson(), 'Unsupported precision retains all prices');
$wire->price = '8.9550';
$rules->saveDefaultRule(1, [...PricingRule::DEFAULT, 'mode' => 'fixed', 'fixed' => ['register' => '1.00', 'renew' => '1.00', 'transfer' => '1.00']], $catalogue->republish(...));
$renew = $catalogue->current(1, $account['account_fingerprint'], 'com', 'renew', 1, 'USD', $clock->now());
same('1.00', $renew->retail, 'Below-cost renewals remain priced'); same('below_cost', $renew->staffAlert, 'Below-cost renewal only alerts staff');
$wire->enabled = false;
same(1, $catalogue->sync(1, $read)['synced'], 'Positive source no-price can refresh');
same(null, Price::where('action', 'register')->firstOrFail()->wholesale, 'Acquisition no-price withdraws term');
same('1.00', $catalogue->renewal(1, $account['account_fingerprint'], 'com', 'USD', $clock->now())->retail, 'No-price preserves published holding renewal retail');
same('34.92', $snapshot->retail, 'Frozen snapshot never repriced');
$nativeSuccessful = true;
echo "Enom adapter to catalogue/PriceSnapshot and failed-read retention passed.\n";
