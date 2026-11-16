<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** A deferred decision could not be saved for retry. Its caller must not move past it or record a repair. */
final class MoneyWorkUnqueued extends \RuntimeException {}
