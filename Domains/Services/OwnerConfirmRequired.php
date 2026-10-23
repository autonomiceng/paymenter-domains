<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** A confirm-class owner action arrived without the confirm step's field; nothing was requested. */
final class OwnerConfirmRequired extends \DomainException
{
    public function __construct() { parent::__construct('Confirmation required.'); }
}
