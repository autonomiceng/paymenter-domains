<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

// Retain the entry point used by renewal execution until its callers move.
class_alias(\Paymenter\Extensions\Servers\Domains\Registrars\Contract\CanonicalName::class, __NAMESPACE__.'\CanonicalName');
