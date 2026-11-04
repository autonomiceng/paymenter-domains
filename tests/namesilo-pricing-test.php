<?php
// Synthetic native fixture only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, CatalogueAcceptance, CatalogueFreshness, Environment, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver, Prices};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, FoundationSchema, PriceCatalogue, PricingRules, RegistrarRegistry};
FoundationSchema::install();
$clock = new class implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-18T12:00:00Z'); } };
$wire = new class implements DeadlineTransport {
    public string $price = '12.34';
    public function send(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey): TransportResponse { throw new LogicException; }
    public function sendWithin(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey, int $timeoutMs): TransportResponse
    {
        same(0, DB::transactionLevel(), 'Price read outside locks');
        return new TransportResponse(200, json_encode(['request' => ['operation' => 'getPrices'], 'reply' => ['code' => 300, 'detail' => 'synthetic',
            'com' => ['registration' => $this->price, 'renew' => '15.00', 'transfer' => '10.00']]]));
    }
};
$registry = new RegistrarRegistry;
$registry->register('namesilo', ['key'], new class implements AccountVerifier {
    public function verify(Environment $environment, #[SensitiveParameter] array $credentials): string
    {
        if (!in_array($credentials['key'], ['synthetic-key-a', 'synthetic-key-b'], true)) { throw new RuntimeException('Unknown identity'); }
        return 'synthetic-stable-id';
    }
}, fn (AccountBinding $binding, array $credentials) => new Driver(new Client($binding, $credentials['key'], $wire), ['register', 'renew'], $clock));
$bindings = new AccountBindings($registry);
Server::create(['id' => 1, 'name' => 'Synthetic', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
try { $bindings->configure(1, 'namesilo', Environment::Test, ['key' => 'unverified-label-or-key']); throw new LogicException('Unknown identity accepted'); }
catch (RuntimeException) {}
same(0, Account::count(), 'Unverified identity cannot configure a real reader');
$expectedBinding = new AccountBinding('namesilo', 1, Environment::Test, 'synthetic-stable-id');
$binding = $bindings->configure(1, 'namesilo', Environment::Test, ['key' => 'synthetic-key-a']);
$account = Account::where('server_id', 1)->firstOrFail();
same(false, $account->processing_enabled, 'Initial NameSilo configuration leaves processing off');
same('synthetic-stable-id', $account->stable_identity, 'Configured account stores verified NameSilo identity');
same($expectedBinding->fingerprint, $account->account_fingerprint, 'Configured account fingerprint derives from verified NameSilo identity');
same($expectedBinding->fingerprint, $binding->fingerprint, 'Configured binding fingerprint derives from verified NameSilo identity');
$driver = $bindings->reader(1);
$rotated = $bindings->configure(1, 'namesilo', Environment::Test, ['key' => 'synthetic-key-b']);
$account = $account->fresh();
same('synthetic-stable-id', $account->stable_identity, 'Rotation retains verified NameSilo identity');
same($expectedBinding->fingerprint, $account->account_fingerprint, 'Rotation retains verified NameSilo account fingerprint');
same($expectedBinding->fingerprint, $rotated->fingerprint, 'Rotation retains verified NameSilo binding fingerprint');
$binding->requireMatch($rotated); same(false, $account->processing_enabled, 'Rotation keeps stable account and processing off');
$query=new Query('example.com',Action::Register,1);
$context=static fn()=>new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+1 minute'),new RequestBudget(1),$clock);
$feed=new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\OfferFeed($driver);
$commands=iterator_to_array((static function()use($feed,$context){yield from $feed->prices($context());})());
same(1,count($commands),'One account price row becomes one normalized command');$first=$commands[0];
same(['register:1','renew:1','transfer:1'],array_keys($first->costs),'Account listing establishes annual prices only');
same('12.3400',$first->costs['register:1']->total,'Annual account quote preserves exact precision');
same('namesilo_account_annual',$first->costs['register:1']->source,'Account source retained');
$wire->price='bad';try{$feed->prices($context());throw new LogicException('Malformed whole-account prices accepted');}catch(DomainException){}
same('12.3400',$first->costs['register:1']->total,'Malformed account reply retains prior observation without disabled evidence');
$wire->price='1.00';$fresh=$feed->prices($context());same('1.0000',$fresh[0]->costs['register:1']->total,'Later independent observation has its own cost');
same('12.3400',$first->costs['register:1']->total,'Earlier observation remains immutable');
$advisory=Prices::rate($driver->quote($query,$context()),$binding,$query,'com',$clock);
same(false,$advisory->persistable,'Short-lived advisory mapping remains fenced from persisted feeds');
// Active Writer/failed-run retention is covered by offer-feed/work/writer, not the retired importer/fill.
$nativeSuccessful=true;echo "NameSilo verified identity, stable rotation and standalone annual feed passed.\n";
