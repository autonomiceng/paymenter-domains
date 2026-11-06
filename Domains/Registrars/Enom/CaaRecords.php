<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
/** The Enom host list holds CAA records, which this extension cannot read or edit. The DNS family stays unknown. */
final class CaaRecords extends \DomainException
{
    public function __construct() { parent::__construct('Enom DNS has CAA records.'); }
}
