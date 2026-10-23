<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\Adoption\RefusalCode;
final class AdoptionRefused extends \DomainException
{
    public function __construct(public readonly RefusalCode $reason) { parent::__construct($reason->message()); }
}
