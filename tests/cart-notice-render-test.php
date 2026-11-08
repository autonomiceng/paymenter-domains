<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The real Livewire render listener and dispatch store establish whether the customer receives the notice.
require __DIR__.'/native-app.php';
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CartCheckoutRefresh, CartNotice};
CartCheckoutRefresh::boot(new class extends CartAdmission { public function __construct() {} });
$listeners = (new ReflectionProperty(Livewire\EventBus::class, 'listeners'))->getValue(app(Livewire\EventBus::class));
$render = end($listeners['render']);
$css = file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/styles.blade.php');
foreach ([$css => true, '<section data-domains-cart-notice>message</section>' => false, '<div>message</div>' => true] as $html => $toast) {
    $cart = new App\Livewire\Cart;
    CartNotice::set($cart, 'Your cart changed', 'Review the total, then check out again.',
        'Your cart changed. Review the total, then check out again.', 'warning');
    same($html, $render($cart,Illuminate\Support\Facades\View::file(dirname(__DIR__).'/Domains/resources/views/partials/styles.blade.php'))($html), 'Render preserves the theme HTML');
    $events = array_map(static fn ($event) => $event->serialize(), Livewire\store($cart)->get('dispatched', []));
    same($toast, collect($events)->contains('name', 'notify'), 'Only a rendered notice element suppresses the toast');
}
$nativeSuccessful = true;
echo "Cart notice delivery distinguishes CSS selectors from rendered elements.\n";
