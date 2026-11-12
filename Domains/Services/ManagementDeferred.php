<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** A current acceptance or prior-outcome hold. Scheduled corrections wait without changing requests. */
final class ManagementDeferred extends \DomainException {}
