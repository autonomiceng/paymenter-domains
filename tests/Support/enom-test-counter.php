<?php
// Shared retained counter for direct offline Enom TEST transport checks.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\Installation;
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
require_once __DIR__.'/FakeRegistrar.php';
$GLOBALS['enomTestCallClock']=new FakeClock(new DateTimeImmutable('2026-10-18T12:00:00Z'));
app()->instance(Clock::class,$GLOBALS['enomTestCallClock']);
app()->instance(Installation::class,new Installation);
app(Installation::class)->install();
