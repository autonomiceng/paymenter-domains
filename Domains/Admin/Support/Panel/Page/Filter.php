<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
/** One list filter, as data: pick one value of a column. */
final class Filter
{
    /** @param array<string,string> $options value => label */
    private function __construct(public readonly string $name, public readonly string $label, public readonly array $options) {}
    /** @param array<string,string> $options value => label */
    public static function select(string $name, string $label, array $options): self { return new self($name, $label, $options); }
}
