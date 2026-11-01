<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Local admission/stale-result refusal; never a provider failure or write authority. */
final class ReadDeferred extends \DomainException
{
    /** Only a local pacing interval is safe to wait out; capacity/quota refusals have no retry hint. */
    public function __construct(string $message = '', public readonly ?int $retryAfterMicros = null) { parent::__construct($message); }
}
