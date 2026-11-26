<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Contract;

/** A local refusal to make or accept a read. Observation must propagate it as a deferral. */
interface ReadRefusal extends \Throwable {}
