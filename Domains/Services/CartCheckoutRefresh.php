<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Classes\{Cart as NativeCart, Price};
use App\Helpers\ExtensionHelper;
use App\Livewire\Cart;
use Livewire\Livewire;

/** Scoped to the native Cart, before its transaction. No native replacement or global request hook. */
final class CartCheckoutRefresh
{
    public static function boot(CartAdmission $admission): void
    {
        Livewire::listen('mount', static function ($component) use ($admission): void {
            if ($component::class !== Cart::class) { return; }
            try { $admission->refresh(); }
            catch (\Throwable $e) { self::unavailable($component, $e); }
        });
        Livewire::listen('hydrate', static function ($component, $memo): void {
            if ($component::class === Cart::class) { \Livewire\store($component)->set('domains.offers', $memo['domains_offers'] ?? null); }
        });
        Livewire::listen('call', static function ($component, $method, $params, $context, $returnEarly) use ($admission): void {
            if ($component::class !== Cart::class || $method !== 'checkout') { return; }
            try {
                $admission->refresh(); $offers = $admission->offers();
                if (!$offers) { return; }
                $total = new Price(['price' => NativeCart::items()->sum(fn ($item) => $item->price->total * $item->quantity), 'currency' => NativeCart::get()->currency]);
                if (\Livewire\store($component)->get('domains.offers') !== $offers
                    || !$component->total || $component->total->total !== $total->total) {
                    // Only the customer can accept a changed retail price; the existing checkout click does that.
                    $component->total = $total;
                    $component->gateways = ExtensionHelper::getCheckoutGateways($total->total, $total->currency->code, 'cart', NativeCart::items());
                    if (!in_array($component->gateway, array_column($component->gateways, 'id'))) { $component->gateway = $component->gateways[0]['id'] ?? null; }
                    $component->notify('Your cart changed. Review the total, then check out again.', 'warning');
                    $returnEarly();
                }
            } catch (\Throwable $e) { self::unavailable($component, $e); $returnEarly(); }
        });
        Livewire::listen('dehydrate', static function ($component, $context) use ($admission): void {
            if ($component::class !== Cart::class) { return; }
            try { $offers = $admission->offers(); } catch (\Throwable) { $offers = null; }
            $context->addMemo('domains_offers', $offers);
        });
    }

    private static function unavailable(Cart $component, \Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::warning('Domains checkout check unavailable.', ['exception_class' => $e::class]);
        $component->notify($e instanceof CheckoutRefreshTimeout || ($e instanceof ReadDeferred && $e->retryAfterMicros !== null)
            ? 'The domain check is taking too long. Your cart is saved. Try checkout again shortly.'
            : 'We couldn’t check this domain. Try checkout again, or edit or remove it from your cart.', 'error');
    }
}
