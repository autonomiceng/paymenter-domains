<?php
// Synthetic dual-driver integration only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CatalogueAcceptance, CatalogueFreshness, Environment, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Price, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client as EnomClient, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver as EnomDriver;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CatalogueAcceptances, CatalogueSync, FoundationSchema, PriceCatalogue, PricingRule, PricingRules, RegistrarRegistry, SalesPolicySchema, SellerPolicies};
FoundationSchema::install(); SalesPolicySchema::install();
$clock = new class implements Clock {
    public DateTimeImmutable $at;
    public function __construct() { $this->at = new DateTimeImmutable('2026-10-18T12:00:00Z'); }
    public function now(): DateTimeImmutable { return $this->at; }
};
$ns = new class implements DeadlineTransport {
    public int $calls = 0; public bool $fail = false; public bool $omit = false; public bool $quarantine = false; public ?Closure $during = null;
    public function send(WireEnvironment $env, WireContext $origin, RegistrarRequest $request, string $key): TransportResponse { throw new LogicException; }
    public function sendWithin(WireEnvironment $env, WireContext $origin, RegistrarRequest $request, string $key, int $timeoutMs): TransportResponse
    {
        same(0, DB::transactionLevel(), 'NameSilo batch outside transactions'); same('getPrices', $request->command, 'Only account price read');
        same([], $request->parameters(), 'No retail or quantity flags'); $this->calls++;
        if ($this->during) { ($this->during)(); $this->during = null; }
        if ($this->fail) { throw new RuntimeException('synthetic-private-key'); }
        $row = ['registration' => '8.25', 'renew' => '10.00', 'transfer' => '9.00'];
        return new TransportResponse(200, json_encode(['request' => ['operation' => 'getPrices'], 'reply' => ['code' => 300, 'detail' => 'synthetic',
            'com' => $this->quarantine ? array_diff_key($row, ['registration' => true]) : $row,
            ...($this->omit ? [] : ['co.uk' => $row])]]));
    }
};
$enom = new class implements Transport {
    public int $calls = 0; public bool $fail = false; public bool $noPrice = false; public int $max = 3; public bool $registry = true;
    public function send(Environment $env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string
    {
        same(0, DB::transactionLevel(), 'Enom catalogue outside transactions'); $this->calls++;
        if ($this->fail) { throw new RuntimeException('synthetic-private-token'); }
        $body = match ($command) {
            ReadCommand::TldInfo => '<TLDs><TLD><TLD>'.$parameters['TLD'].'</TLD><Registration><Minimum>1</Minimum><Maximum>'.$this->max.'</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs>',
            ReadCommand::Attributes => $this->registry ? '<ResponseCount>0</ResponseCount>' : '<Attributes><Attribute><Name>unsupported</Name></Attribute></Attributes>',
            ReadCommand::ProductPrice => '<ProductPrice><ProductEnabled>'.($this->noPrice ? 'false' : 'true').'</ProductEnabled><Price>8.9550</Price></ProductPrice>',
            default => throw new LogicException('Unexpected read'),
        };
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
    }
};
$registry = new RegistrarRegistry;
$verifier = new class implements AccountVerifier { public function verify(Environment $env, array $credentials): string { return 'offline'; } };
$registry->register('namesilo', ['key'], $verifier, fn ($binding, $credentials) => new Driver(new Client($binding, $credentials['key'], $ns), [], $clock));
$registry->register('enom', ['key'], $verifier, fn ($binding, $credentials) => new EnomDriver(new EnomClient($binding, 'offline', $credentials['key'], $enom), ['com']));
$bindings = new AccountBindings($registry); $bound = [];
foreach ([1 => 'enom', 2 => 'namesilo'] as $id => $key) {
    Server::create(['id' => $id, 'name' => 'Synthetic', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
    $bound[$id] = $bindings->configure($id, $key, Environment::Test, ['key' => 'synthetic-private-key']);
    $bindings->setProcessing($id, true);
}
$context = fn (int $id, int $budget = 20) => new RequestContext($bound[$id], RequestOrigin::Background, $clock->now()->modify('+5 minutes'), new RequestBudget($budget), $clock);
$row = ['register' => [1], 'renew' => [1], 'transfer' => [1], 'registry' => true, 'renewal_safe' => true];
$reject = static function (callable $call): void { try { $call(); } catch (Throwable) { return; } throw new RuntimeException('Expected refusal.'); };
$rules = new PricingRules; $prices = new PriceCatalogue($rules); $sync = new CatalogueSync($bindings, $prices);
$reject(fn () => $sync->refresh(2, $context(2))); same(0, $ns->calls, 'No acceptance means no reads');
$reject(fn () => new CatalogueAcceptance($bound[2], 1, ['com' => [...$row, 'register' => [3]]]));
$a1 = CatalogueAcceptances::save($bound[1], 0, ['com' => [...$row, 'register' => [1, 3]]]);
$a2 = CatalogueAcceptances::save($bound[2], 0, ['com' => $row, 'co.uk' => $row]);
same(1, CatalogueAcceptances::load($bound[2])->revision, 'Acceptance durable across reads');
$raw = DB::table('settings')->where('key', CatalogueAcceptances::KEY)->first()->value;
expect(!str_contains($raw, 'renewal_safe') && !str_contains($raw, $bound[1]->fingerprint), 'Native acceptance encrypted');
$reject(fn () => CatalogueAcceptances::save($bound[2], 0, ['com' => $row]));
// Bound native encrypted TEXT capacity before saving, including envelope overhead.
$large = []; $fit = [];
for ($i = 0; $i < 500; $i++) {
    $suffix = 'x'.chr(97 + intdiv($i, 26)).chr(97 + $i % 26);
    $large[$suffix] = [...$row, 'register' => [1, 3]];
    $encoded = json_encode(['fingerprint' => $bound[1]->fingerprint, 'revision' => 2, 'endings' => $large, 'currency' => 'USD', 'unit' => 'exact_term_annual']);
    if (strlen($encoded) > 32768) { break; } $fit = $large;
}
CatalogueAcceptances::save($bound[1], 1, $fit);
$stored = DB::table('settings')->where('key', CatalogueAcceptances::KEY)->where('settingable_id', 1)->value('value');
expect(strlen($stored) < 65535, 'Boundary acceptance ciphertext fits native TEXT');
$reject(fn () => CatalogueAcceptances::save($bound[1], 2, $large));
same($stored, DB::table('settings')->where('key', CatalogueAcceptances::KEY)->where('settingable_id', 1)->value('value'), 'Oversize cannot corrupt existing acceptance');
CatalogueAcceptances::save($bound[1], 2, ['com' => [...$row, 'register' => [1, 3]]]);
DB::flushQueryLog(); DB::enableQueryLog();
same(['synced' => 2, 'failed' => []], $sync->refresh(2, $context(2, 1)), 'One batch imports ordinary and compound endings');
$queries = DB::getQueryLog(); DB::disableQueryLog(); DB::flushQueryLog();
$ruleReads = array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'select ')
    && in_array('domains_pricing_default_rule', $query['bindings'], true));
// Each ending has one publication resolution plus three independent current() eligibility checks.
same(8, count($ruleReads), 'Import resolves one publication rule per locked TLD across all actions');
foreach (Price::where('server_id', 2)->get() as $price) {
    same(hash('sha256', json_encode(PricingRule::DEFAULT, JSON_THROW_ON_ERROR)), $price->eligibility['rule_hash'],
        'Imported rates hash the same default used for pricing');
}
same(1, $ns->calls, 'One getPrices read for all endings and actions');
same('8.2500', $prices->current(2, $bound[2]->fingerprint, 'co.uk', 'register', 1, 'USD', $clock->now())->wholesale, 'Literal multi-label suffix lookup');
same(0, Price::where('server_id', 2)->where('term', 3)->count(), 'NameSilo does not extrapolate');
same('2026-10-18T13:00:00+00:00', Price::where('server_id', 2)->firstOrFail()->fresh_until->format(DATE_ATOM), 'Shared cadence plus failure margin');
same(['synced' => 1, 'failed' => []], $sync->refresh(1, $context(1)), 'Enom accepted exact term batch');
same('26.8650', $prices->current(1, $bound[1]->fingerprint, 'com', 'register', 3, 'USD', $clock->now())->wholesale, 'Enom selected-term exact total');
foreach (Tld::all() as $tld) { same(false, $tld->enabled, 'Import never selects or enables a seller'); $tld->update(['enabled' => true]); }
$sellers = new SellerPolicies($prices, $bindings); $sellers->initialize('com');
$sellers->change('com', 1, 1, true, true, $clock->now()); $old = $sellers->selection('com', 'register', 3, $clock->now());
$sellers->change('com', 2, 2, true, true, $clock->now());
$reject(fn () => DB::transaction(fn () => $sellers->admit([$old], $clock->now())));
// Legacy cost refreshes cannot inherit publication proof or extend its cadence.
same(2, $prices->sync(2, fn () => [new \Paymenter\Extensions\Servers\Domains\Data\CatalogueRate('register', 1, '8.25', 'synthetic', $clock->now(), $clock->now()->modify('+7 days'))])['synced'], 'Legacy cost-only refresh');
$reject(fn () => $sellers->selection('com', 'register', 1, $clock->now()));
expect(!isset(Price::where('server_id', 2)->where('tld', 'com')->where('action', 'register')->firstOrFail()->eligibility['catalogue_revision']), 'Legacy writer drops publication proof');
same(2, $sync->refresh(2, $context(2))['synced'], 'Accepted sync restores publication proof');
$beforeRenewal = $prices->renewal(1, $bound[1]->fingerprint, 'com', 'USD', $clock->now());
$clock->at = $clock->at->modify('+10 minutes');
same(1, $sync->refresh(1, $context(1))['synced'], 'Non-selling holder continues refreshing');
expect($prices->renewal(1, $bound[1]->fingerprint, 'com', 'USD', $clock->now())->revision > $beforeRenewal->revision, 'Non-seller holder renewal updated');
$beforeTlds = DB::table('domains_tlds')->orderBy('id')->get()->toJson();
$before = DB::table('domains_prices')->orderBy('id')->get()->toJson(); $ns->fail = $enom->fail = true;
same(0, $sync->refresh(1, $context(1))['synced'], 'Enom failure retained'); same(0, $sync->refresh(2, $context(2))['synced'], 'NameSilo failure retained');
same($before, DB::table('domains_prices')->orderBy('id')->get()->toJson(), 'Failures preserve every price byte/revision/freshness');
same($beforeTlds, DB::table('domains_tlds')->orderBy('id')->get()->toJson(), 'Read failures retain all TLD flags and bounds');
$ns->fail = $enom->fail = false; $ns->omit = true;
$compound = DB::table('domains_prices')->where('tld', 'co.uk')->get()->toJson();
same(['synced' => 1, 'failed' => ['.co.uk']], $sync->refresh(2, $context(2)), 'Missing ending is failure, never no-price');
same($compound, DB::table('domains_prices')->where('tld', 'co.uk')->get()->toJson(), 'Omission retains old evidence');
$ns->omit = false; $enom->max = 1;
same(1, $sync->refresh(1, $context(1))['synced'], 'Changed live bounds accepted without extrapolation');
$sellers->change('com', 3, 1, true, true, $clock->now());
// Reopening configured bounds cannot restore a live-unsupported retained term.
Tld::where('server_id', 1)->update(['max_years' => 3]);
$retained = $prices->current(1, $bound[1]->fingerprint, 'com', 'register', 3, 'USD', $clock->now());
$unsupported = new \Paymenter\Extensions\Servers\Domains\Data\SaleSelection('com', 1, 'enom', $bound[1]->fingerprint, 4, 'register', 3, 'USD', $retained->id, $retained->revision);
$reject(fn () => DB::transaction(fn () => $sellers->admit([$unsupported], $clock->now())));
$reject(fn () => $sellers->selection('com', 'register', 3, $clock->now()));
$sellers->change('com', 4, 2, true, true, $clock->now());
Tld::where('server_id', 1)->update(['max_years' => 1]);
// Out-of-range retained terms do not poison rule republication or regain publication.
$three = Price::where('server_id', 1)->where('term', 3)->firstOrFail()->revision;
$summary = $rules->saveDefaultRule(1, PricingRule::DEFAULT, $prices->republish(...));
same([], $summary['failed'], 'Republication skips out-of-range retained rows');
same($three, Price::where('server_id', 1)->where('term', 3)->firstOrFail()->revision, 'Out-of-range bytes unchanged');
$enom->noPrice = true; $sync->refresh(1, $context(1));
same(null, Price::where('server_id', 1)->where('action', 'register')->where('term', 1)->firstOrFail()->wholesale, 'Positive source no-price withdraws acquisition');
same($beforeRenewal->retail, $prices->renewal(1, $bound[1]->fingerprint, 'com', 'USD', $clock->now())->retail, 'No-price retains holder retail');
same(false, Tld::where('server_id', 1)->firstOrFail()->register_enabled, 'No current annual renewal closes acquisitions');
$oldCart = $sellers->selection('com', 'register', 1, $clock->now());
// Explicit malformed evidence invalidates previously sellable rows, unlike an omission/read failure.
$priorRenewal = $prices->renewal(2, $bound[2]->fingerprint, 'com', 'USD', $clock->now());
$ns->quarantine = true;
same(['synced' => 1, 'failed' => [], 'quarantined' => ['.com']], $sync->refresh(2, $context(2)), 'Malformed sold ending quarantines while valid compound ending imports');
$reject(fn () => $sellers->selection('com', 'register', 1, $clock->now()));
$reject(fn () => DB::transaction(fn () => $sellers->admit([$oldCart], $clock->now())));
$quarantined = Price::where('server_id', 2)->where('tld', 'com')->orderBy('id')->get();
foreach ($quarantined as $price) {
    same(null, $price->wholesale, 'Quarantined unknown never becomes zero');
    same(null, $price->fresh_until, 'Quarantined cost carries no freshness authority');
    same('unknown_price', $price->hold_reason, 'Quarantined action refuses price admission');
    same('Price unavailable', $price->eligibility['staff_label'], 'Staff has explicit unavailable label');
    if ($price->action !== 'renew') { same(null, $price->retail, 'Quarantined acquisition is not offered'); }
}
$retainedUnknown = $prices->renewal(2, $bound[2]->fingerprint, 'com', 'USD', $clock->now());
same($priorRenewal->retail, $retainedUnknown->retail, 'Quarantine preserves holder renewal retail');
expect($retainedUnknown->retained && $retainedUnknown->revision > $priorRenewal->revision, 'Quarantine advances retained holder revision');
same('unknown_price', $retainedUnknown->staffAlert, 'Holder unknown cost alerts staff');
same(false, Tld::where('server_id', 2)->where('tld', 'com')->firstOrFail()->transfer_enabled, 'Quarantine closes transfer sale');
$snapshot = $quarantined->toJson();
$rules->saveDefaultRule(2, PricingRule::DEFAULT, $prices->republish(...));
same($snapshot, Price::where('server_id', 2)->where('tld', 'com')->orderBy('id')->get()->toJson(), 'Republication cannot turn malformed evidence into confirmed no-price');
$unavailable = static fn (string $ending, DateTimeImmutable $at) => new \Paymenter\Extensions\Servers\Domains\Data\CatalogueUnavailable($bound[2], $ending, $at);
$accepted = CatalogueAcceptances::load($bound[2]);
foreach ([$clock->now()->modify('-1 second'), $clock->now()->modify('+1 second')] as $invalidAt) {
    $reject(fn () => $prices->unavailable($unavailable('com', $invalidAt), $accepted, static fn () => null, $clock->now()));
}
same($snapshot, Price::where('server_id', 2)->where('tld', 'com')->orderBy('id')->get()->toJson(), 'Stale/future quarantine keeps revisions and evidence');
$reject(fn () => $prices->unavailable($unavailable('com', $clock->now()), $a1, static fn () => null, $clock->now()));
$neverPriced = new CatalogueAcceptance($bound[2], $accepted->revision, ['feedback' => $row]);
$prices->unavailable($unavailable('feedback', $clock->now()), $neverPriced, static fn () => null, $clock->now());
same(3, Price::where('server_id', 2)->where('tld', 'feedback')->whereNull('wholesale')->count(), 'Never-priced quarantine records all accepted actions as unknown');
$reject(fn () => $prices->current(2, $bound[2]->fingerprint, 'feedback', 'register', 1, 'USD', $clock->now()));
$prior = DB::table('domains_prices')->where('server_id', 2)->get()->toJson();
$ns->during = fn () => CatalogueAcceptances::save($bound[2], 1, ['com' => $row, 'co.uk' => $row]);
same(0, $sync->refresh(2, $context(2))['synced'], 'Quarantine cannot bypass acceptance revision race');
same($prior, DB::table('domains_prices')->where('server_id', 2)->get()->toJson(), 'Raced quarantine preserves every price byte');
$ns->quarantine = false;
same(2, $sync->refresh(2, $context(2))['synced'], 'Fresh valid evidence recovers quarantined ending');
$oldCart = $sellers->selection('com', 'register', 1, $clock->now());
$revoked = CatalogueAcceptances::save($bound[2], 2, ['com' => [...$row, 'renewal_safe' => false], 'co.uk' => $row]);
$reject(fn () => $sellers->selection('com', 'register', 1, $clock->now()));
$reject(fn () => DB::transaction(fn () => $sellers->admit([$oldCart], $clock->now())));
$prior = DB::table('domains_prices')->where('server_id', 2)->get()->toJson();
$ns->during = fn () => CatalogueAcceptances::save($bound[2], 3, ['com' => $row, 'co.uk' => $row]);
same(0, $sync->refresh(2, $context(2))['synced'], 'Revision change during read rejects all stale results');
same($prior, DB::table('domains_prices')->where('server_id', 2)->get()->toJson(), 'Acceptance race retains old evidence');
$calls = $ns->calls;
$reject(fn () => $sync->refresh(2, $context(1))); same($calls, $ns->calls, 'Wrong context binding refuses before reads');
$bindings->setProcessing(2, false); // spending pause does not discard holder observation authority
same(2, $sync->refresh(2, $context(2))['synced'], 'Paused account still refreshes holder prices');
$calls = $ns->calls;
$setting = Server::findOrFail(2)->settings()->where('key', 'domains_credentials')->firstOrFail();
$setting->value = json_encode(['key' => 'changed-without-verification']); $setting->save();
$reject(fn () => $sync->refresh(2, $context(2))); same($calls, $ns->calls, 'Unverified credentials refuse before reads');
$bindings->configure(2, 'namesilo', Environment::Test, ['key' => 'synthetic-private-key']);
Server::findOrFail(2)->update(['enabled' => false]);
$reject(fn () => $sync->refresh(2, $context(2))); same($calls, $ns->calls, 'Disabled server refuses before read');
$reject(fn () => DB::transaction(fn () => $sync->refresh(1, $context(1))));
// Bounded Enom runs rotate their starting suffix and expose explicit scheduler subsets.
$enom->noPrice = false;
$enAcceptance = CatalogueAcceptances::load($bound[1]);
CatalogueAcceptances::save($bound[1], $enAcceptance->revision, ['com' => $row, 'net' => $row]);
$first = $sync->refresh(1, $context(1, 5)); same(1, $first['synced'], 'Bounded run imports one ending');
$clock->at = $clock->at->modify('+1800 seconds');
$next = $sync->refresh(1, $context(1, 5)); same(1, $next['synced'], 'Next cadence serves the other ending');
expect($first['failed'] !== $next['failed'], 'Tail ending is not permanently starved');
same(1, $sync->refresh(1, $context(1, 5), new CatalogueFreshness, ['net'])['synced'], 'Explicit bounded subset reaches tail holder');
$reject(fn () => $sync->refresh(1, $context(1), new CatalogueFreshness, ['invalid']));
$clock->at = $clock->at->modify('+2 hours');
same(true, $prices->renewal(2, $bound[2]->fingerprint, 'com', 'USD', $clock->now())->retained, 'Failure margin expiry retains holder retail');
$nativeSuccessful = true;
echo "Shared dual-driver catalogue, acceptance revisions, publication and retained-holder checks passed.\n";
