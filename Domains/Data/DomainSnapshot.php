<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Nullable fields are independently unknown; an absent flag is never false. */
final readonly class DomainSnapshot
{
    public function __construct(public AccountBinding $binding, public string $name, public ?bool $owned = null,
        public ?DateEvidence $expiry = null, public ?bool $providerAutoRenew = null,
        public ?bool $privacy = null, public ?bool $locked = null, public ?string $providerReference = null, public ?bool $active = null)
    {
        Query::requireName($name);
        if ($providerReference !== null && !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $providerReference)) {
            throw new \InvalidArgumentException('Invalid opaque provider reference.');
        }
    }
}
