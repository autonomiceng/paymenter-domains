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
                $held = session()->get(self::shownKey().'.lines', session()->get(self::shownKey(), []));
                $review = session()->get(self::shownKey().'.review');
                $admission->refresh();
                if(ComplimentaryAccounts::authorization((int)\Illuminate\Support\Facades\Auth::id())){self::show($component,self::cartTotal());}
                // A new price may extend the review; the customer's own term/quantity edit invalidates it.
                $choices = static fn (array $lines) => array_map(static fn (array $line) => [$line['plan_id'], $line['quantity']], $lines);
                if (is_array($review) && ($review['shown'] ?? null) === $held
                    && $choices($held) === $choices(self::snapshot(self::shown()))) {
                    CartNotice::restore($component, $review['notice'] ?? null);
                }
                $was = self::changedAmounts($held);
                if ($was) { self::changed($component, self::cartTotal(), $was); }
            }
            catch (CartLinesRemoved $e) {
                self::removed($component, $e);
                $was = self::changedAmounts($held);
                if ($was) { self::priceNotice($component, $was, $e); }
            }
            catch (\Throwable $e) { self::unavailable($component, $e); }
        });
        Livewire::listen('hydrate', static function ($component, $memo): void {
            if ($component::class !== Cart::class) { return; }
            \Livewire\store($component)->set('domains.offers', $memo['domains_offers'] ?? null);
            \Livewire\store($component)->set('domains.shown', $memo['domains_shown'] ?? null);
            CartNotice::restore($component, $memo['domains_notice'] ?? null);
        });
        Livewire::listen('call', static function ($component, $method, $params, $context, $returnEarly) use ($admission): void {
            if ($component::class !== Cart::class) { return; }
            if (in_array($method, ['applyCoupon', 'removeCoupon', 'updateQuantity', 'removeProduct'], true)) {
                \Livewire\store($component)->set('domains.customer_edit', true);
                CartNotice::clear($component);
            }
            if ($method !== 'checkout') { return; }
            OrderContact::clear();
            if (!\Illuminate\Support\Facades\Auth::check()) { return; }
            try {
                try { $admission->refresh(); }
                catch (CartLinesRemoved $e) { self::removed($component, $e); $returnEarly(); return; }
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
                } else {
                    // This click accepts the quote that the page displayed, including a retained review notice.
                    CartNotice::clear($component);
                    session()->forget(self::shownKey().'.review');
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
        Livewire::listen('render', static function ($component) use ($admission) {
            if ($component::class !== Cart::class) { return null; }
            // Native line prices are dynamic on every render, not just checkout. Never advance the
            // signed/session shown amount silently when an unrelated Livewire update races publication.
            $held = (array) \Livewire\store($component)->get('domains.shown');
            if (\Livewire\store($component)->get('domains.customer_edit')
                && ComplimentaryAccounts::authorization((int)\Illuminate\Support\Facades\Auth::id())) {
                // Native customer edits reload line models and recalculate from persisted paid plans.
                // Reapply the account grant before rendering both line prices and the native total.
                try { $admission->refresh(); self::show($component, self::cartTotal()); }
                catch (CartLinesRemoved $e) { self::show($component, self::cartTotal()); self::removed($component, $e); }
                catch (\Throwable $e) { self::unavailable($component, $e); }
            }
            if ($held) {
                try {
                    $beforeOffers = (array) \Livewire\store($component)->get('domains.offers');
                    $changed = static fn () => \Livewire\store($component)->get('domains.customer_edit')
                        ? self::changedOffers($held, $beforeOffers, $admission->offers()) : self::changedShown($held);
                    $was = $changed();
                    if ($was) {
                        $admission->refresh();
                        $was = $changed();
                        if ($was) { self::changed($component, self::cartTotal(), $was); }
                    }
                } catch (CartLinesRemoved $e) { self::show($component, self::cartTotal()); self::removed($component, $e); }
                catch (\Throwable $e) { self::unavailable($component, $e); }
            }
            return static function ($html) use ($component) {
                $notice = CartNotice::get($component);
                if ($notice && !preg_match('/<[a-z][^>]*\s'.CartNotice::MARKER.'(?=[\s=>])/i', $html)) { $component->notify($notice['toast'], $notice['type']); }
                return $html;
            };
        });
        Livewire::listen('dehydrate', static function ($component, $context) use ($admission): void {
            if ($component::class !== Cart::class) { return; }
            try { $offers = $admission->offers(); } catch (\Throwable) { $offers = null; }
            $context->addMemo('domains_offers', $offers);
            try { $shown = self::shown(); } catch (\Throwable) { $shown = null; }
            $context->addMemo('domains_shown', $shown);
            if ($shown !== null) {
                $lines = self::snapshot($shown);
                session()->put(self::shownKey().'.lines', $lines);
                $notice = CartNotice::get($component);
                $review = ($notice['title'] ?? null) === 'Your cart changed' ? ['notice' => $notice, 'shown' => $lines] : null;
                $context->addMemo('domains_notice', $review['notice'] ?? null);
                if ($review) { session()->put(self::shownKey().'.review', $review); }
                else { session()->forget(self::shownKey().'.review'); }
            }
            OrderContact::clear();
        });
    }

    private static function removed(Cart $component, CartLinesRemoved $removed): void
    {
        try { self::show($component, self::cartTotal()); }
        catch (\Throwable $error) {
            \Illuminate\Support\Facades\Log::warning('Domains cart recalculation failed after removal.', ['exception_class' => $error::class]);
        }
        $component->dispatch('cartUpdated');
        CartNotice::set($component, 'Domain removed', $removed->getMessage(), $removed->getMessage(), 'warning', $removed->names);
    }

    private static function shownKey(): string
    { return 'domains.cart_shown.'.NativeCart::get()->ulid; }

    private static function changed(Cart $component, ?Price $total, array $was): void
    {
        self::show($component, $total);
        self::priceNotice($component, $was);
    }

    private static function priceNotice(Cart $component, array $was, ?CartLinesRemoved $removed = null): void
    {
        $previous = CartNotice::get($component);
        if (($previous['title'] ?? null) === 'Your cart changed') {
            // Keep pending details for unchanged lines; a new change replaces that line's old amount.
            $was = array_replace(array_intersect_key($previous['was'] ?? [], self::shown()), $was);
        }
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

    private static function changedShown(array $held): array
    {
        $was = [];
        foreach (self::shown() as $id => $amount) {
            if (isset($held[$id]) && $held[$id] !== $amount) { $was[$id] = $held[$id]; }
        }
        return $was;
    }

    /** Published domain retail is independent of coupon/quantity edits to displayed amounts. */
    private static function changedOffers(array $held, array $before, array $offers): array
    {
        $before = array_column($before, null, 0); $was = [];
        foreach ($offers as $offer) {
            $old = $before[$offer[0]] ?? null;
            if ($old && array_slice($old, 0, 3) === array_slice($offer, 0, 3)
                && $old[3] !== $offer[3] && isset($held[$offer[0]])) { $was[$offer[0]] = $held[$offer[0]]; }
        }
        return $was;
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
        \Illuminate\Support\Facades\Log::warning('Domains checkout check unavailable.', CartHandoffFailure::context($e,'checkout_check'));
        if ($e instanceof \App\Exceptions\DisplayException) { $component->notify($e->getMessage(), 'error'); return; }
        if ($e instanceof CheckoutRefreshTimeout || $e instanceof ReadDeferred) {
            CartNotice::set($component, 'We could not get your price', 'Try again shortly.', 'We could not get your price. Try again shortly.', 'error');
            return;
        }
        $component->notify('We could not check this domain. Edit or remove it from your cart, then try checkout again.', 'error');
    }

}
