<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real native obligations are required for retirement, frozen evidence and once-only late credit.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Edit, Intent, Query, Context};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement, NativeCancellation, NativeIdentity, NativeIntentSchema, PayCheck, ReminderSchema};
expect(method_exists(PayCheck::class, 'catalogue'), 'PayCheck needs a dormant local offer fence before trusting its minute cache');
require __DIR__.'/Support/offer-commerce.php';
extract(offerCommerceFixture());
$pay = new class($bindings, $clock) extends PayCheck { use OfferCommerceRoute; };
$pay->fixtureOffers = $offers;
$user = commerceUser(); $public = $storefront->offer('com', 'register', 1);
$cart = commerceCart($user); commerceAdd($cart, $public['product'], 'payment-fence-placeholder.com'); commerceCheckout($user, $cart);
$op = Operation::sole(); $invoice = Invoice::findOrFail($op->invoice_id); $before = $reads->calls;
Cache::put('domains.pay-check.'.$op->id, 'clear', 60);
$revision = $ports->readOffers(new Query($binding, ['com'], 'register'), Context::Staff)[0]->revision;
$ports->apply(Edit::override($binding, 'com', new Intent(sell: false)), $revision);
same('unavailable', $pay->stop($invoice)['why'] ?? null, 'Stale local evidence refuses payment instead of trusting the old clear cache');
same(['pending', 'awaiting_payment', $before], [$invoice->fresh()->status, $op->fresh()->state, $reads->calls], 'Local staleness neither retires nor reads the provider');
$nativeSuccessful = true;
echo "Offer pay check: ok\n";
