<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Contract;

/** A definite pre-send refusal, without database, credential or provider exception details. */
final class LiveReservationLimit extends \DomainException
{
    public function __construct() { parent::__construct('Our live account safety check stopped this request before sending.'); }
}

// PHP does not autoload a catch type. Register the old name before this exception can be thrown.
class_alias(LiveReservationLimit::class, 'Paymenter\Extensions\Servers\Domains\Services\LiveReservationLimit');
