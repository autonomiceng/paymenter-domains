<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** No provider request entered. Never retain a database exception or request arguments. */
final class EnomTestCallLimit extends \DomainException
{
    public function __construct() { parent::__construct('Enom send refused before sending: send limit, setting, counter or UTC day change.'); }
}
