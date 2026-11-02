<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Too many distinct child hosts to publish without dropping known evidence. */
final class GlueDiscoveryLimit extends \DomainException
{
    public const HOSTS=50;
    public function __construct() { parent::__construct('Child-host discovery limit reached.'); }
}
