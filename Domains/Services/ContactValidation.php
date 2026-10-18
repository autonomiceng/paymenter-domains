<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Field paths only; never include submitted contact values in errors. */
final class ContactValidation extends \DomainException
{
    public function __construct(public readonly array $fields) { parent::__construct('Check the contact details.'); }
}
