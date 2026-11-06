<?php
// Independent scenario exhaustion for native matrices; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
final class NativeScenarioInventory
{
    private array $pending;
    public function __construct(array $expected)
    {
        same(count($expected),count(array_unique($expected)),'Expected scenarios are unique');
        $this->pending=array_fill_keys($expected,true);
    }
    public function completed(string $scenario): void
    {
        expect(isset($this->pending[$scenario]),'Unexpected or repeated scenario: '.$scenario);
        unset($this->pending[$scenario]);
    }
    public function finish(): void
    {
        same([],array_keys($this->pending),'Missing required scenarios: '.implode(', ',array_keys($this->pending)));
    }
}
