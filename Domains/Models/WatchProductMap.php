<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
final class WatchProductMap extends WatchRecord
{
    protected $table = 'domains_watch_product_maps';
    protected $attributes = ['revision' => 1, 'enabled' => false];
    protected $casts = ['revision' => 'integer', 'enabled' => 'boolean'];
    protected array $identity = ['id', 'product_id'];
    protected function validateRecord(): void
    {
        if (!is_string($this->property_key) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/D', $this->property_key) !== 1 ||
            preg_match('/(?:^|[_-])(?:alias(?:es)?|additional[_-]domains)(?:[_-]|$)|(?:alias(?:es)?|additionalDomains)$/i', $this->property_key) || $this->enabled ||
            !is_string($this->reason) || trim($this->reason) === '' || strlen($this->reason) > 1000 || !$this->edited_by) {
            throw new \DomainException('A scalar primary property key, editor and reason are required.');
        }
        $product = \App\Models\Product::findOrFail($this->product_id);
        if (!$product->server || $product->server->extension === 'Domains' || $product->settings()->where('key', 'domains_catalogue')->exists()) {
            throw new \DomainException('Choose a hosting product.');
        }
    }
}
