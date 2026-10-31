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
extract(commerceFixture(true)); $clock = new FakeClock($now);
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], new class implements AccountVerifier {
    public function verify(Environment $environment, #[SensitiveParameter] array $credentials): string { return $credentials['token']; }
}, static fn ($binding, $credentials) => new Driver(new Client($binding, $credentials['token'], 'synthetic'), ['com']));
$bindings = new AccountBindings($registry); app()->instance(Clock::class, $clock);
$eligibility = new CheckoutEligibility($bindings, $clock);
$store = new Storefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
$controller = new StorefrontController($store); storefrontViews();
(new CartAdmission($native, $sellers, $eligibility, $clock))->boot();
EnomTestSendLimitSchema::install(); config(['domains.enom_test_sends_per_day' => 200, 'domains.enom_test_sends_per_minute' => 100000]);
$logger = new class extends Psr\Log\AbstractLogger {
    public array $warnings = [];
    public function log($level, string|Stringable $message, array $context = []): void {
        if ($level === 'warning') { $this->warnings[] = [$message, $context]; }
    }
};
Log::swap($logger); $user = commerceUser(); $cart = commerceCart($user);
app('request')->cookies->set('cart', $cart->ulid);
$session = app('request')->session();
$request = static function (array $input = []) use ($session, $user): Request {
    $request = Request::create('/domains/order', 'POST', $input, server: ['REMOTE_ADDR' => '192.0.2.32']);
    $request->setLaravelSession($session); $request->setUserResolver(static fn () => $user); return $request;
};
$order = static function (string $name, string $term = '1') use ($controller, $request, $user): array {
    RateLimiter::clear('cart-add:user:'.$user->id);
    RateLimiter::clear('domains.search.ip:'.hash('sha256', '192.0.2.32'));
    RateLimiter::clear('domains.search.user:'.$user->id);
    $key = FormKeys::issue($request(), 'order:'.$name);
    $req = $request(['domain' => $name, 'action' => 'register', 'term' => $term, '_request_key' => $key]);
    return [$controller->order($req), $req];
};
$reply = static fn ($form) => '<interface-response><Command>Check</Command><ErrCount>0</ErrCount><Done>true</Done>'
    .'<Domains><Domain><Name>'.$form['SLD'].'.'.$form['TLD'].'</Name><RRPCode>210</RRPCode>'
    .'<IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP></Domain></Domains></interface-response>';
$name = 'handoff-placeholder.com';
resetWire(['reply' => $reply]); same('available', $store->check($name)['status'], 'First real parsed Check offers registration');
resetWire(['timeout' => true]); [$response, $used] = $order($name);
same(route('domains.domains.search', ['name' => $name]), $response->getTargetUrl(), 'Failed second Check returns to search');
same(0, $cart->items()->count(), 'No native cart item after failed fresh Check');
same(1, count($GLOBALS['enomWire']['forms']), 'Exactly one physical fresh Check, no automatic retry');
$unconfirmed = 'We couldn’t confirm this name right now. Try again.';
$generic = 'We couldn’t add that name to your cart. Check the latest results below and try again.';
same($unconfirmed, $session->get('domains_notice'), 'An unconfirmed name says so in plain words');
same(['Domains cart handoff failed.', ['exception_class' => DomainException::class, 'exception_message_key' => 'eligibility_unconfirmed']], end($logger->warnings), 'Fixed key identifies the refusal without raw exception text');
try { FormKeys::verify($used, 'order:'.$name); expect(false, 'Failed handoff key cannot replay'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { same(419, $error->getStatusCode(), 'Retry needs fresh search intent'); }
foreach (['missing_flag', 'wrong_name', 'late_success'] as $mode) {
    // Separate transport cases must outlast the shared failed-check backoff.
    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::now()->addSeconds(11));
    $clock->instant = $now;
    resetWire(['reply' => static function ($form) use ($reply, $mode, $clock, $now): string {
        if ($mode === 'late_success') { $clock->instant = $now->modify('+10 seconds'); }
        $xml = $reply($form);
        return match ($mode) {
            'missing_flag' => str_replace('<IsEAP>0</IsEAP>', '', $xml),
            'wrong_name' => str_replace($form['SLD'].'.'.$form['TLD'], 'other-placeholder.com', $xml),
            default => $xml,
        };
    }]);
    $order($name);
    same(0, $cart->items()->count(), $mode.' leaves no cart item');
    same('eligibility_unconfirmed', end($logger->warnings)[1]['exception_message_key'], $mode.' uses an allowlisted refusal');
    same($unconfirmed, $session->get('domains_notice'), $mode.' says the name could not be confirmed');
    same(1, count($GLOBALS['enomWire']['forms']), $mode.' has one physical Check');
}
$clock->instant = $now; resetWire(['reply' => $reply]);
Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::now()->addSeconds(11));
$order($name, '01'); same('invalid_action_or_term', end($logger->warnings)[1]['exception_message_key'], 'Bare DomainException is distinguished');
same($generic, $session->get('domains_notice'), 'Other refusals keep the general notice');
same([], $GLOBALS['enomWire']['forms'], 'Invalid term never reads provider');
$product = $native->product($tlds[0], 'register'); $product->update(['stock' => 0]);
$order($name); same('offer_unavailable', end($logger->warnings)[1]['exception_message_key'], 'Withdrawn offer is distinct');
same($generic, $session->get('domains_notice'), 'A withdrawn offer keeps the general notice');
$product->update(['stock' => null]);
resetWire(['reply' => $reply]); [$response] = $order($name);
same(route('cart'), $response->getTargetUrl(), 'Fresh user retry succeeds without widening any limits');
same(1, $cart->items()->count(), 'Retry adds exactly one native item');
same(1, count($GLOBALS['enomWire']['forms']), 'Retry makes one independent fresh Check');
$order($name); same('domain_already_in_cart', end($logger->warnings)[1]['exception_message_key'], 'Duplicate cart admission is distinct');
config(['domains.enom_test_sends_per_day' => 0]); resetWire(['reply' => $reply]);
$order('budget-placeholder.com');
same('test_call_limit', end($logger->warnings)[1]['exception_message_key'], 'Native TEST send limit refusal has its own typed key');
same([], $GLOBALS['enomWire']['forms'], 'Native send limit refusal makes zero physical sends');
config(['domains.enom_test_sends_per_day' => 200]);
$secret = 'private-placeholder.com credential-placeholder provider-reference-placeholder';
foreach ([new DomainException($secret), new DomainException('Domain already in cart. '.$secret),
    new RuntimeException('Domain already in cart.'), new class('Domain already in cart.') extends DomainException {},
    new DomainException($secret, previous: new DomainException('Domain already in cart.'))] as $error) {
    same('unclassified', CartHandoffFailure::key($error), 'Unknown text/class/previous cause cannot enter diagnostics');
}
Illuminate\Support\Facades\Event::listen(App\Events\CartItem\Creating::class, static function () use ($secret): never {
    throw new DomainException($secret, previous: new RuntimeException($secret));
});
resetWire(['reply' => $reply]); $order('private-placeholder.com');
same(['Domains cart handoff failed.', ['exception_class' => DomainException::class, 'exception_message_key' => 'unclassified']], end($logger->warnings), 'Actual catch path withholds raw message and nested cause');
same(1, $cart->items()->count(), 'Unknown failure cannot create a second cart item');
Illuminate\Support\Carbon::setTestNow();
echo "Storefront handoff transport, strict evidence, timing and retry checks passed.\n";
$nativeSuccessful = true;
