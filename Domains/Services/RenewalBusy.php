<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Database contention during a renewal authority read. Nothing changed; the caller retries as it does for unread facts. */
final class RenewalBusy extends \RuntimeException {}
