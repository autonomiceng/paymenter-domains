<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Checkout refresh removed domain lines staff took off sale. The message is the customer's only notice. */
final class CartLinesRemoved extends \RuntimeException
{
    /** @param list<string> $names */
    public function __construct(public readonly array $names)
    {
        $last = array_pop($names);
        $list = $names === [] ? $last : implode(', ', $names).' and '.$last;
        parent::__construct($names === []
            ? $list.' is no longer for sale, so we removed it from your cart. Search again to find another domain.'
            : $list.' are no longer for sale, so we removed them from your cart. Search again to find other domains.');
    }
}
