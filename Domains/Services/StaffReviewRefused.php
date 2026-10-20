<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Only fixed, user-safe staff validation text may cross the notification boundary. */
final class StaffReviewRefused extends \DomainException {}
