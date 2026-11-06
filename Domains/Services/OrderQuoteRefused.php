<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Catalogue\Cost;

/** A refreshed order quote was refused (below a fixed retail, for example). Carries the quoted acquisition cost,
 * which lives only in this request, so pay can tell a price change from a stop. */
final class OrderQuoteRefused extends \DomainException
{
    public function __construct(\DomainException $refusal, public readonly Cost $cost)
    { parent::__construct($refusal->getMessage(), 0, $refusal); }
}
