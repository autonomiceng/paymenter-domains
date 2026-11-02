<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** A definite pre-send refusal, without database, credential or provider exception details. */
final class LiveReservationLimit extends \DomainException
{
    public function __construct() { parent::__construct('Our live account safety check stopped this request before sending.'); }
}
