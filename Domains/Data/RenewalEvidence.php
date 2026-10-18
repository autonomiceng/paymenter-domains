<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
/** Sanitized acknowledgement identity; confirmation still requires original charge and expiry. */
final readonly class RenewalEvidence
{
    public function __construct(public Query $query, public ?string $orderReference)
    {
        if ($query->action !== Action::Renew || ($orderReference !== null && !preg_match('/^[1-9][0-9]{0,19}$/D', $orderReference))) {
            throw new \InvalidArgumentException('Invalid renewal acknowledgement.');
        }
    }
}
