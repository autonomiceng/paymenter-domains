<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/**
 * The one customer cart message for this request: a short title, the sentence, and old line prices on a price change.
 * A theme shows it with @includeIf('domains::cart-notice') above the lines and @includeIf('domains::cart-was') in
 * each line's price. A theme without the notice include gets the same words as a toast instead.
 */
final class CartNotice
{
    public const MARKER = 'data-domains-cart-notice';

    /** @param list<string> $names domains to set in mono inside the sentence
     *  @param array<int, string> $was old line price by cart item id */
    public static function set(object $component, string $title, string $text, string $toast, string $type, array $names = [], array $was = []): void
    {
        \Livewire\store($component)->set('domains.cart_notice', compact('title', 'text', 'toast', 'type', 'names', 'was'));
    }

    /** @return array{title: string, text: string, toast: string, type: string, names: list<string>, was: array<int, string>}|null */
    public static function get(?object $component): ?array
    {
        return $component ? \Livewire\store($component)->get('domains.cart_notice') : null;
    }
}
