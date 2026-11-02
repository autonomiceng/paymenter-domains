<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName};

/** Replace only with newer verified facts; a failed read never becomes this command. */
final readonly class FactsObserved implements Command
{
    public function __construct(private AccountBinding $account, private string $ending, public Facts $facts)
    {
        CanonicalName::parse('example.'.$ending, [$ending]);
    }
    public function binding(): AccountBinding { return $this->account; }
    public function tld(): string { return $this->ending; }
}
