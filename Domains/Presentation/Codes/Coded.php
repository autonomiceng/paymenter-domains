<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation\Codes;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, State};
/** A provider code turned into a State. An unmapped code reads Not recognized and keeps the code, which staff see under
 * the word so they can report it; the provider's own text is never the word. */
final class Coded
{
    private function __construct(public readonly State $state, public readonly ?Fact $code) {}
    /** @param array<string,State> $table */
    public static function from(array $table, string $code): self
    { return isset($table[$code]) ? new self($table[$code], null) : new self(State::NotRecognized, Fact::code($code)); }
    public static function state(State $state): self { return new self($state, null); }
}
