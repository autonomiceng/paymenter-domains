<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** A departure blocking line found under locks. Thrown so the money decision rolls everything back. */
final class DepartureBlocked extends \DomainException
{
    /** @param array{line:int,invoice:?int,operation:?int,hold_domain:?int} $block */
    public function __construct(public readonly array $block)
    { parent::__construct('Departure blocked on line '.$block['line'].'.'); }
}
