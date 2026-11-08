<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native cart, real parser/transport, synthetic wire only.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/Support/enom-wire.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Log, RateLimiter};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock};
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\StorefrontController;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CartAdmission, CartHandoffFailure, CheckoutEligibility, EnomTestSendLimitSchema, RegistrarRegistry, Storefront};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
require dirname(__DIR__).'/Domains/routes/web.php';
extract(commerceFixture(true));
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], new class implements AccountVerifier {
    public function verify(Environment $environment, #[SensitiveParameter] array $credentials): string { return $credentials['token']; }
}, static fn ($binding, $credentials) => new Driver(new Client($binding, $credentials['token'], 'synthetic'), ['com']));
$bindings = new AccountBindings($registry); app()->instance(Clock::class, $clock);
// The physical reader changes; the same explicit active offer authority still owns this checkout.
activeFixturePorts($bindings,$ports,$clock);activeFixtureOffers($bindings,$offers);
$eligibility = activeFixtureEligibility($bindings, $clock);
$store = activeFixtureStorefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
$controller = new StorefrontController($store); storefrontViews();
(activeFixtureAdmission($native, $sellers, $eligibility, $clock))->boot();
EnomTestSendLimitSchema::install(); config(['domains.enom_test_sends_per_day' => 200, 'domains.enom_test_sends_per_minute' => 100000]);
$user=commerceUser();$identity=['user_id'=>$user->id];$name='cached-refusal-placeholder.com';
$selection=$sellers->selection('com','register',1,$clock->now());
resetWire(['reply'=>static fn()=>'<interface-response><Command>Check</Command><ErrCount>1</ErrCount><Done>true</Done><errors><Err1>Private synthetic detail</Err1></errors><RRPCode>115</RRPCode></interface-response>']);
$errors=[];$contexts=[];for($i=0;$i<2;$i++){try{$eligibility->observe($name,$selection,$identity);$errors[]='Read unexpectedly accepted';}
 catch(DomainException $e){foreach(['search','cart_handoff','checkout_check']as$phase){$contexts[$i][$phase]=CartHandoffFailure::context($e,$phase);}if(!$e instanceof \Paymenter\Extensions\Servers\Domains\Services\EligibilityUnconfirmed||$e->registrarCode!=='115'){$errors[]='Cached refusal loses validated registrar code';}}}
if(($contexts[0]??null)!==($contexts[1]??null)){$errors[]='Cached refusal changes safe diagnostic context';}
same(1,count($GLOBALS['enomWire']['forms']),'Repeated refusal uses one provider read');expect($errors===[],implode('; ',$errors));
$nativeSuccessful=true;echo "Cached typed registrar refusal passed\n";
