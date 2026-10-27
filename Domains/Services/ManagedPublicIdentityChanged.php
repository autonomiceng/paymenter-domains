<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Roll back relink before retiring work whose persisted identity no longer matches. */
final class ManagedPublicIdentityChanged extends \DomainException {}
