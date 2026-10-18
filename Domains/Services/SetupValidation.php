<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Field names only, never submitted values or provider exceptions. */
final class SetupValidation extends \DomainException
{
    public function __construct(public readonly array $fields) { parent::__construct('Check the domain settings.'); }
}
