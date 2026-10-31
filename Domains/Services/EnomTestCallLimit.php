<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** No provider request entered. Never retain a database exception or request arguments. */
final class EnomTestCallLimit extends \DomainException
{
    /** @param string $reason over_limit, bad_setting, unreadable, send_guard, or send_check when no finer reason is known */
    public function __construct(public readonly string $reason='send_check') { parent::__construct('Our send check stopped this Enom request before sending: send limit, setting, counter or UTC day change.'); }
}
