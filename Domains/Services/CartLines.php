<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Classes\Cart as NativeCart;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

/** Read-only view of this plugin's lines in the visitor's native cart. A line it cannot map is not its line. */
final class CartLines
{
    public function __construct(private readonly NativeCatalogue $native) {}

    /** @return array<int, array{name: string, action: string, term: int}> keyed by cart line id */
    public function current(): array
    {
        $cart = NativeCart::get();
        if (!$cart->exists || ($cart->user_id !== null && (int) $cart->user_id !== (int) Auth::id())) { return []; }
        $lines = [];
        foreach ($cart->items as $item) {
            $name = $item->checkout_config['domain'] ?? null;
            if (!is_string($name) || !$item->plan) { continue; }
            try { $mapping = $this->native->mapping(Product::findOrFail($item->product_id), $item->plan); }
            catch (\Throwable) { continue; }
            if ($mapping) { $lines[(int) $item->id] = ['name' => $name, 'action' => $mapping['action'], 'term' => (int) $mapping['term']]; }
        }
        return $lines;
    }

    /** The line a "replace" value names, when it is one of this cart's domain lines. */
    public function find(mixed $id): ?array
    {
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/D', $id)) { return null; }
        $line = $this->current()[(int) $id] ?? null;
        return $line ? ['id' => (int) $id, ...$line] : null;
    }

    public function contains(string $name): bool
    {
        return in_array($name, array_column($this->current(), 'name'), true);
    }
}
