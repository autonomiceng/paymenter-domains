<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{FeedRun, PricedFeed};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CatalogueFreshness, Environment, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Driver as EnomDriver, OfferFeed as EnomFeed};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client as EnomClient, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client as NameSiloClient, Driver as NameSiloDriver, OfferFeed as NameSiloFeed};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};

$clock = new class implements Clock {
    public DateTimeImmutable $at;
    public function __construct() { $this->at = new DateTimeImmutable('2026-11-02T20:00:00Z'); }
    public function now(): DateTimeImmutable { return $this->at; }
};
$context = static fn (AccountBinding $binding, int $calls = 5) => new RequestContext($binding, RequestOrigin::Background,
    $clock->now()->modify('+130 seconds'), new RequestBudget($calls), $clock);
$refuses = static function (callable $work): void {
    try { $work(); } catch (DomainException|InvalidArgumentException|RuntimeException $e) { return; }
    throw new LogicException('Expected feed refusal.');
};
$indexed = static function (iterable $commands): array {
    $out = []; foreach ($commands as $command) { $out[$command->tld()] = $command; } return $out;
};
$enomWire = new class implements Transport {
    public array $calls = [];
    public string $currency = 'USD';
    public string $register = '10.0010';
    public string $renew = '11.0000';
    public string $enabled = 'true';
    public bool $omit = false;
    public ?int $fail = null;
    public ?Closure $during = null;
    public function send(Environment $environment, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string
    {
        $this->calls[] = [$environment, $command, $parameters, $timeoutMs];
        if ($this->during !== null) { ($this->during)(); }
        $header = '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>';
        if ($command === ReadCommand::Tlds) { return $header.'<TLDList><TLD>com</TLD><TLD>co.uk</TLD><TLD>org</TLD></TLDList></interface-response>'; }
        if ($command === ReadCommand::TldInfo) {
            return $header.'<TLDs><TLD><TLD>'.$parameters['TLD'].'</TLD><Registration><Minimum>1</Minimum><Maximum>3</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs></interface-response>';
        }
        if ($command === ReadCommand::Attributes) { return $header.'<ResponseCount>0</ResponseCount></interface-response>'; }
        $years = $parameters['Years'];
        if ($this->fail === $years) { throw new RuntimeException('Synthetic read failure.'); }
        // Recorded synthetic Text shape, including per-row currency (not just envelope currency).
        $body = "Command={$command->value}\nErrCount=0\nDone=true\nCount=2\n";
        foreach (['com', 'co.uk'] as $i => $tld) {
            $n = $i + 1;
            $body .= "TLD$n=$tld\nCurrency$n={$this->currency}\nRegisterPrice$n=999.00\nResellerPriceRenew$n={$this->renew}\nRenewEnabled$n=true\nResellerPriceTran$n=12.0000\nTransferEnabled$n=false\n";
            if (!$this->omit) { $body .= "ResellerPriceReg$n={$this->register}\nRegisterEnabled$n={$this->enabled}\n"; }
        }
        return $body;
    }
};
foreach ([Environment::Test, Environment::Live] as $environment) {
    $binding = new AccountBinding('enom', 1, $environment, 'synthetic-account');
    $driver = new EnomDriver(new EnomClient($binding, 'synthetic-account', 'placeholder-key', $enomWire), ['com', 'co.uk']);
    $feed = new EnomFeed($driver, new CatalogueFreshness(48));
    $enomWire->calls = [];
    $c = $context($binding); $rows = $indexed($feed->prices($c));
    same('10.0010', $rows['com']->costs['register:1']->total ?? null, 'Enom feed returns exact reseller cost observations');
    same(5, count($enomWire->calls), 'Four buckets and one suffix list, independent of catalogue size');
    same(0, $c->budget->remaining(), 'Every feed wire consumes the bound request budget');
    same([1, 2, 5, 10], array_column(array_column(array_slice($enomWire->calls, 0, 4), 2), 'Years'), 'All four bucket requests use explicit years');
    foreach ($enomWire->calls as [$env, $command, $parameters, $timeout]) {
        same($environment, $env, 'Both environments remain explicitly bound');
        expect($timeout > 0 && $timeout <= 25000, 'Feed preserves transport deadline');
        if ($command === ReadCommand::RetailPricing) { same(1, $parameters['TLDOnly'], 'Account reseller feed has no hypothetical domain quantity'); }
    }
    $savedTime = $clock->at; $clock->at = new DateTimeImmutable('2026-11-05T12:00:00Z');
    $approved = new EnomFeed($driver, new CatalogueFreshness, expansion: \Paymenter\Extensions\Servers\Domains\Catalogue\TermSupport::REVIEWED_EVIDENCE['enom']);
    $approvedRows = $indexed($approved->prices($context($binding)));
    same($environment === Environment::Test ? '22.0000' : null, $approvedRows['com']->costs['renew:2']->total ?? null,
        'Reviewed TEST two-year total is twice bucket-one renewal cost; Live has no derived quote');
    same(false, isset($approvedRows['co.uk']->costs['renew:2']), 'Unreviewed suffix has no derived renewal');
    $approvedFacts = $approved->facts('com', $context($binding, 2))->facts;
    if ($environment === Environment::Test) {
        $terms = \Paymenter\Extensions\Servers\Domains\Catalogue\TermSupport::renewal('enom', 'com', $approvedFacts,
            $approvedRows['com']->costs, new DateTimeImmutable('2027-10-18'), $clock->now(), environment: $environment);
        same([true, '22.0000'], [$terms[2]['available'], $terms[2]['cost']], 'Real feed supplies annual support, reviewed evidence and exact two-year total');
    }
    $clock->at = $savedTime;

    same('20.0020', $rows['co.uk']->costs['register:2']->total, 'Compound suffix and annual-to-term exact total');
    same('100.0100', $rows['com']->costs['register:10']->total, 'No float rounding on ten-year totals');
    same('30.0030', $rows['com']->costs['register:3']->total ?? null, 'Documented bracket covers year three exactly');
    same('90.0090', $rows['com']->costs['register:9']->total ?? null, 'Documented bracket covers year nine exactly');
    same(false, $rows['com']->costs['transfer:1']->enabled, 'Explicit disabled differs from absent');
    same(null, $rows['com']->costs['transfer:1']->total, 'Disabled is not zero-priced');
    same([], $rows['org']->costs, 'Listed but unpriced ending is discovery only');
    $oldAt = $rows['com']->costs['register:1']->observedAt;
    $clock->at = $clock->at->modify('+1 hour');
    $before = count($enomWire->calls); $facts = $feed->facts('co.uk', $context($binding, 2));
    same(2, count($enomWire->calls) - $before, 'Cold facts use exactly two reads, never price-per-term reads');
    same([1, 2, 3], $facts->facts->terms['register'], 'Registry term facts preserved');
    same([1], $facts->facts->terms['renew'] ?? [], 'Positive enabled bulk renewal proves one annual term');
    same([], $facts->facts->terms['transfer'] ?? [], 'Disabled bulk transfer proves no term');
    same(172800, $rows['com']->costs['renew:1']->freshUntil->getTimestamp() - $oldAt->getTimestamp(), 'Daily costs survive one missed run');
    same([[], true, false, false], [$facts->facts->registryFields, $facts->facts->renewalSafe, $facts->facts->requiresNameservers, $facts->facts->autoRenewOnly], 'Independent registry and renewal facts');
    same($oldAt, $rows['com']->costs['register:1']->observedAt, 'Facts do not freshen previous costs');
    same($clock->now(), $facts->facts->observedAt, 'Facts have their own observation age');
    $enomWire->renew = '0.0000';
    $missingRenew = $indexed($feed->prices($context($binding)));
    same(false, isset($missingRenew['com']->costs['renew:1']), 'Zero renewal price remains unknown');
    same([], $feed->facts('com', $context($binding, 2))->facts->terms['renew'], 'Unknown annual renewal cannot enable sales');
    $enomWire->renew = '11.0000';
    $explicit = new EnomFeed($driver, new CatalogueFreshness, terms: [1 => [1], 2 => [2, 3, 4], 5 => [5], 10 => [10]]);
    same('30.0030', $indexed($explicit->prices($context($binding)))['com']->costs['register:3']->total, 'Only supplied bucket evidence enables another exact term');
    $refuses(fn () => new EnomFeed($driver, new CatalogueFreshness, terms: [5 => [4]]));
    $enomWire->enabled = 'false'; $enomWire->register = '0.00';
    same(false, $indexed($feed->prices($context($binding)))['com']->costs['register:1']->enabled, 'Provider disabled sentinel is positive evidence');
    $enomWire->enabled = 'unknown';
    same(false, isset($indexed($feed->prices($context($binding)))['com']->costs['register:1']), 'Unknown flag cannot disable a retained price');
    $enomWire->omit = true;
    same(false, isset($indexed($feed->prices($context($binding)))['com']->costs['register:1']), 'Omitted row fields retain prior observations');
    $enomWire->omit = false; $enomWire->enabled = 'true'; $enomWire->register = '0.00';
    same(false, isset($indexed($feed->prices($context($binding)))['com']->costs['register:1']), 'Enabled zero is malformed, never disabled or free');
    $enomWire->register = 'broken'; $refuses(fn () => $feed->prices($context($binding)));
    $enomWire->register = '10.0010'; $enomWire->currency = 'EUR';
    $refuses(fn () => $feed->prices($context($binding))); $enomWire->currency = 'USD';
    $enomWire->fail = 5; $refuses(fn () => $feed->prices($context($binding))); $enomWire->fail = null;
    $before = count($enomWire->calls); $refuses(fn () => $feed->prices($context($binding, 4)));
    same($before, count($enomWire->calls), 'Insufficient whole-feed request capacity sends nothing');
    $wrong = new AccountBinding('enom', 2, $environment, 'other-account');
    $refuses(fn () => $feed->prices($context($wrong)));
    same($before, count($enomWire->calls), 'Wrong account cannot send or borrow feed data');
    // The queue/writer owns durable generations. A later start during reads invalidates this buffered run.
    $generation = 1; $expected = 1; $currentBinding = $binding;
    $guard = static function () use (&$generation, $expected, &$currentBinding, $binding): void {
        $binding->requireMatch($currentBinding);
        if ($generation !== $expected) { throw new DomainException('Superseded run.'); }
    };
    $enomWire->during = static function () use (&$generation): void { $generation = 2; };
    $refuses(fn () => FeedRun::prices($feed, $context($binding), $guard));
    $generation = 1;
    $enomWire->during = static function () use (&$currentBinding, $wrong): void { $currentBinding = $wrong; };
    $refuses(fn () => FeedRun::prices($feed, $context($binding), $guard));
    $enomWire->during = null; $currentBinding = $binding;
    same(3, count(FeedRun::prices($feed, $context($binding), $guard)), 'Current bound run exposes observations only after the final guard');
}
$nameSiloWire = new class implements DeadlineTransport {
    public array $calls = [];
    public array $rows = ['com' => ['registration' => '10.01', 'renew' => '11.00', 'transfer' => '12.00'],
        'co.uk' => ['registration' => '20.00', 'renew' => '21.00', 'transfer' => '22.00'],
        'org' => ['registration' => '0.00', 'renew' => '11.00', 'transfer' => '12.00']];
    public function send(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey): TransportResponse { throw new LogicException('Deadline required.'); }
    public function sendWithin(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey, int $timeoutMs): TransportResponse
    {
        $this->calls[] = [$environment, $context, $request->command, $request->parameters()];
        return new TransportResponse(200, json_encode(['request' => ['operation' => 'getPrices', 'ip' => '192.0.2.1'], 'reply' => ['code' => 300, 'detail' => 'synthetic', ...$this->rows]], JSON_THROW_ON_ERROR));
    }
};
foreach ([Environment::Test, Environment::Live] as $environment) {
    $binding = new AccountBinding('namesilo', 3, $environment, 'synthetic-account');
    $feed = new NameSiloFeed(new NameSiloDriver(new NameSiloClient($binding, 'placeholder-key', $nameSiloWire), clock: $clock), new CatalogueFreshness);
    $before = count($nameSiloWire->calls); $rows = $indexed($feed->prices($context($binding, 1)));
    same(1, count($nameSiloWire->calls) - $before, 'NameSilo feed uses exactly one getPrices');
    same('10.0100', $rows['com']->costs['register:1']->total, 'NameSilo exact annual account price');
    same('21.0000', $rows['co.uk']->costs['renew:1']->total, 'Dotted keys do not use dot-path lookup');
    same(['register:1', 'renew:1', 'transfer:1'], array_keys($rows['com']->costs), 'Annual-only without invented terms');
    same(['register:1', 'renew:1', 'transfer:1'], array_keys($rows['org']->costs), 'Quarantined row retracts all annual costs');
    foreach ($rows['org']->costs as $cost) {
        same(false, $cost->enabled, 'Quarantined cost is unavailable');
        same(null, $cost->total, 'Quarantined cost is unknown, never zero');
    }
    same([$environment === Environment::Test ? WireEnvironment::Sandbox : WireEnvironment::Live, WireContext::Background, 'getPrices', []], end($nameSiloWire->calls), 'Bound environment, batch origin, and no retail flag');
    $refuses(fn () => $feed->facts('com', $context($binding)));
    same($before + 1, count($nameSiloWire->calls), 'Unverified NameSilo metadata cannot be fabricated from prices');
    $bad = new AccountBinding('namesilo', 4, $environment, 'other-account');
    $refuses(fn () => $feed->prices($context($bad, 1)));
    same($before + 1, count($nameSiloWire->calls), 'Cross-account NameSilo read sends nothing');
}
same(172800, (new CatalogueFreshness)->maxAge(), 'Default price freshness covers two daily intervals');
putenv('DOMAINS_PRICE_FRESHNESS_SECONDS=86400');
same(172800, (new CatalogueFreshness)->maxAge(), 'Environment cannot override staff price freshness');
putenv('DOMAINS_PRICE_FRESHNESS_SECONDS');
foreach ([23, 169] as $invalid) { $refuses(fn () => new CatalogueFreshness($invalid)); }
echo "Neutral feed adapter checks passed.\n";
