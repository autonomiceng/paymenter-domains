<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

final readonly class Query
{
    public function __construct(public string $name, public Action $action, public int $years)
    {
        self::requireName($name);
        if ($years < 1 || $years > 10) { throw new \InvalidArgumentException('Invalid requested term.'); }
    }
    /** Require already canonical ASCII identity; storefront normalization/eligibility is separate. */
    public function matches(self $other): bool
    {
        return $this->name === $other->name && $this->action === $other->action && $this->years === $other->years;
    }
    public static function requireName(string $name): void
    {
        \Paymenter\Extensions\Servers\Domains\Data\CanonicalName::requireCanonical($name);
    }
}
