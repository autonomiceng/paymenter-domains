<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use App\Livewire\Cart as Checkout;
use App\Models\Cart;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Once;
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, OrderContact};

/** Run native order creation for a prepared synthetic registration without rendering the cart. */
function readyRegistrationCheckout(Cart $cart, CartAdmission $admission): void
{
    $cookies = request()->cookies;
    $previous = $cookies->get('cart');
    $cookiePath = Cookie::forget('cart')->getPath();
    $queued = Cookie::queued('cart', path: $cookiePath);
    $cookies->set('cart', $cart->ulid);
    Once::flush();
    try {
        OrderContact::clear();
        $admission->refresh();
        $checkout = new Checkout;
        $checkout->use_credits = false;
        $checkout->mount();
        expect($checkout->total !== null && $checkout->total->price > 0, 'Ready registration starts with a payable cart');
        $checkout->checkout();
        expect($checkout->getErrorBag()->isEmpty(), 'Native registration checkout has no validation errors');
        expect($cart->fresh() === null, 'Native registration checkout consumed its cart');
    } finally {
        OrderContact::clear();
        $cookies->remove('cart');
        if ($previous !== null) { $cookies->set('cart', $previous); }
        Cookie::unqueue('cart', $cookiePath);
        if ($queued !== null) { Cookie::queue($queued); }
        Once::flush();
    }
}
