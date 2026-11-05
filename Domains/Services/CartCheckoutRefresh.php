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
            try {
                $held = session()->get(self::shownKey(), []);
                $admission->refresh();
                $was = self::changedAmounts($held);
                if ($was) { self::changed($component, self::cartTotal(), $was); }
            }
            catch (CartLinesRemoved $e) {
                self::show($component, self::cartTotal()); self::removed($component, $e);
                $was = self::changedAmounts($held);
                if ($was) { self::changed($component, self::cartTotal(), $was, $e); }
            }
            catch (\Throwable $e) { self::unavailable($component, $e); }
        });
        Livewire::listen('hydrate', static function ($component, $memo): void {
            if ($component::class !== Cart::class) { return; }
            \Livewire\store($component)->set('domains.offers', $memo['domains_offers'] ?? null);
            \Livewire\store($component)->set('domains.shown', $memo['domains_shown'] ?? null);
        });
        Livewire::listen('call', static function ($component, $method, $params, $context, $returnEarly) use ($admission): void {
            if ($component::class !== Cart::class || $method !== 'checkout') { return; }
            OrderContact::clear();
            if (!\Illuminate\Support\Facades\Auth::check()) { return; }
            try {
                try { $admission->refresh(); }
                catch (CartLinesRemoved $e) { self::show($component, self::cartTotal()); self::removed($component, $e); $returnEarly(); return; }
                if ($params !== []) { OrderContact::begin(\Illuminate\Support\Facades\Auth::user(), $params[0] ?? null); }
                foreach ($admission->contactTlds() as $tld) { OrderContact::capture(\Illuminate\Support\Facades\Auth::user(), $tld); }
                $offers = $admission->offers();
                if (!$offers) { return; }
                $total = self::cartTotal();
                if (\Livewire\store($component)->get('domains.offers') !== $offers
                    || !$component->total || $component->total->total !== $total->total) {
                    // Only the customer can accept a changed retail price; the existing checkout click does that.
                    // Strike the amount the buyer last saw on the line (coupon and tax included), never the list price.
                    $held = (array) \Livewire\store($component)->get('domains.shown'); $was = [];
                    foreach (self::shown() as $id => $now) { if (isset($held[$id]) && $held[$id] !== $now) { $was[$id] = $held[$id]; } }
                    self::changed($component, $total, $was);
                    OrderContact::clear(); $returnEarly();
                }
            } catch (ContactValidation $e) {
                foreach (OwnerFormValidation::contact($e)->messages('', registrar:$tld->registrar_key ?? 'enom') as $field => $message) { $component->addError($field, $message); }
                $component->notify($params === []
                    ? 'Check your '.implode(', ', $e->labels()).' in Account. Save your details, then return to the cart and try checkout again.'
                    : 'Check the domain contact details below before payment.', 'error');
                OrderContact::clear(); $returnEarly();
            } catch (\Throwable $e) { OrderContact::clear(); self::unavailable($component, $e); $returnEarly(); }
        });
        // Themes without the notice include keep the toast, with the same words.
        Livewire::listen('render', static function ($component) {
            if ($component::class !== Cart::class) { return null; }
            return static function ($html) use ($component) {
                $notice = CartNotice::get($component);
                if ($notice && !str_contains($html, CartNotice::MARKER)) { $component->notify($notice['toast'], $notice['type']); }
                return $html;
            };
        });
        Livewire::listen('dehydrate', static function ($component, $context) use ($admission): void {
            if ($component::class !== Cart::class) { return; }
            try { $offers = $admission->offers(); } catch (\Throwable) { $offers = null; }
            $context->addMemo('domains_offers', $offers);
            try { $shown = self::shown(); } catch (\Throwable) { $shown = null; }
            $context->addMemo('domains_shown', $shown);
            if ($shown !== null) { session()->put(self::shownKey(), self::snapshot($shown)); }
            OrderContact::clear();
        });
    }

    private static function shownKey(): string
    { return 'domains.cart_shown.'.NativeCart::get()->ulid; }

    private static function changed(Cart $component, ?Price $total, array $was, ?CartLinesRemoved $removed = null): void
    {
        self::show($component, $total);
        $text = ($removed ? $removed->getMessage().' ' : '').'Review the total, then check out again.';
        CartNotice::set($component, 'Your cart changed', $text,
            'Your cart changed. '.$text, 'warning', $removed?->names ?? [], $was);
    }

    private static function cartTotal(): ?Price
    {
        return NativeCart::items()->isEmpty() ? null
            : new Price(['price' => NativeCart::items()->sum(fn ($item) => $item->price->total * $item->quantity), 'currency' => NativeCart::get()->currency]);
    }

    /** Each line's amount as the cart shows it, by cart item id. */
    private static function shown(): array
    {
        return NativeCart::items()->mapWithKeys(fn ($item) => [$item->id => number_format($item->price->total * $item->quantity, 2, '.', '')])->all();
    }

    /** Session reloads may follow the customer's own Edit, which retains the line id. */
    private static function snapshot(array $shown): array
    {
        return NativeCart::items()->mapWithKeys(fn ($item) => [$item->id => [
            'plan_id' => (int) $item->plan_id, 'quantity' => (int) $item->quantity, 'amount' => $shown[$item->id],
        ]])->all();
    }

    private static function changedAmounts(array $held): array
    {
        $was = [];
        foreach (self::snapshot(self::shown()) as $id => $now) {
            $before = $held[$id] ?? null;
            if (is_array($before) && $before['plan_id'] === $now['plan_id'] && $before['quantity'] === $now['quantity']
                && $before['amount'] !== $now['amount']) { $was[$id] = $before['amount']; }
        }
        return $was;
    }

    /** Native updateTotal() is private; mirror it so the page shows what the next click buys. */
    private static function show(Cart $component, ?Price $total): void
    {
        $component->total = $total;
        if (!$total) { return; }
        $component->gateways = ExtensionHelper::getCheckoutGateways($total->total, $total->currency->code, 'cart', NativeCart::items());
        if (!in_array($component->gateway, array_column($component->gateways, 'id'))) { $component->gateway = $component->gateways[0]['id'] ?? null; }
    }

    private static function unavailable(Cart $component, \Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::warning('Domains checkout check unavailable.', ['exception_class' => $e::class]);
        if ($e instanceof \App\Exceptions\DisplayException) { $component->notify($e->getMessage(), 'error'); return; }
        if ($e instanceof CheckoutRefreshTimeout || ($e instanceof ReadDeferred && $e->retryAfterMicros !== null)) {
            $text = 'The domain check is taking too long. Your cart is saved. Try checkout again shortly.';
            CartNotice::set($component, 'Try again shortly', $text, $text, 'error'); return;
        }
        $component->notify('We couldn’t check this domain. Try checkout again, or edit or remove it from your cart.', 'error');
    }

    private static function removed(Cart $component, CartLinesRemoved $e): void
    {
        $component->dispatch('cartUpdated');
        CartNotice::set($component, 'Domain removed', $e->getMessage(), $e->getMessage(), 'warning', $e->names);
    }
}
