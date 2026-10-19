<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Tests;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsContext,DnsQuery};
final class FakeDnsReader implements DnsReader
{
    public array $calls = [];
    public function __construct(public array $answers = []) {}
    public function read(DnsQuery $query, DnsContext $context): DnsAnswer
    {
        if (!$context->reserve()) { return DnsAnswer::unknown($query); }
        $this->calls[] = $query->key();
        $answer = $this->answers[$query->key()] ?? DnsAnswer::unknown($query);
        if (is_callable($answer)) { $answer = $answer($query); }
        return $context->live() && $answer instanceof DnsAnswer && $answer->query->key() === $query->key() ? $answer : DnsAnswer::unknown($query);
    }
}
