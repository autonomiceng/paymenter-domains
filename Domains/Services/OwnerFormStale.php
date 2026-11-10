<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** The saved details changed after the owner opened the form, in another tab or by staff; nothing was requested. */
final class OwnerFormStale extends \DomainException
{
    public const MESSAGE = 'Your contact details changed after you opened this form, so nothing was saved. Open Edit to see the current details and try again.';
    public function __construct() { parent::__construct('Opened details changed.'); }
}
