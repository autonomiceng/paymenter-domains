<?php
// Adapted from Enom 444f31c transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/commerce.php';
require __DIR__.'/native-commerce.php';
require_once __DIR__.'/native-financial-snapshot.php';
require_once __DIR__.'/reserved-fixtures.php';
use App\Models\{Credit, InvoiceTransaction, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, ContactRecovery, ContactSetup, InvoiceBinding, NativeDomainGuard, HolderPricing, PricingRule, Rdap, Refunds, TransferInputs, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture(true)); $clock = new FakeClock($now);
Carbon\Carbon::setTestNow($now); Carbon\CarbonImmutable::setTestNow($now);
$server = App\Models\Server::find($tlds[0]->server_id);
$server->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);
$rdap = new Rdap(fn () => throw new RuntimeException('Synthetic offline lookup'));
$eligibility = new CheckoutEligibility($bindings,$clock,new TransferPrecheck($rdap));
$adapter = new CartAdmission($native,$sellers,$eligibility,$clock); $adapter->boot();
(new InvoiceBinding($adapter,$native,$prices,$clock))->boot();
(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
$user = commerceUser(); $other = commerceUser(); $product = $native->product($tlds[0],'transfer');
$refunds = new Refunds($clock); $inputs = new TransferInputs($clock,$refunds);
$new = static function (string $name, bool $paid=true) use($user,$product): Operation {
    $cart=commerceCart($user); commerceAdd($cart,$product,$name); commerceCheckout($user,$cart);
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    if($paid){ App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount); }
    return $op->fresh();
};
$post = static function ($actor, mixed $code, string $method='POST', bool $query=false): Request {
    $r=Request::create('/internal-transfer-input'.($query?'?transfer_code=forbidden':''),$method,['transfer_code'=>$code]);
    $r->setUserResolver(fn()=>$actor); return $r;
};
$unrelated=$new(placeholderComFixtureName('transfer-unrelated-placeholder.com'),false);
App\Models\ServiceCancellation::create(['service_id'=>$unrelated->service_id,'type'=>'end_of_period','reason'=>'Unrelated service']);
