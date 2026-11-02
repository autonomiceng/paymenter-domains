<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName};

/** Partial observations: the writer retains omitted costs and rejects older observations.
 * Costs never change fact timestamps. Disabled is positive provider evidence, not a failed read. */
final readonly class FeedObserved implements Command
{
    public array $costs;
    public function __construct(private AccountBinding $account, private string $ending, array $costs)
    {
        CanonicalName::parse('example.'.$ending, [$ending]);
        $indexed = [];
        foreach ($costs as $cost) {
            if (!$cost instanceof Cost || isset($indexed[$cost->key()])) {
                throw new \InvalidArgumentException('Invalid or duplicate feed observation.');
            }
            $indexed[$cost->key()] = $cost;
        }
        if ($indexed === []) { throw new \InvalidArgumentException('Explicit feed observation required.'); }
        $this->costs = $indexed;
    }
    public function binding(): AccountBinding { return $this->account; }
    public function tld(): string { return $this->ending; }
    public function __debugInfo(): array { return ['tld' => $this->ending]; }
    public function __serialize(): never { throw new \LogicException('Feed observations are server-only.'); }
}
