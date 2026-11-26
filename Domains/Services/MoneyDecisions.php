<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Money\Native\{MoneyDecisions as NativeDecisions, MoneyLockContext};

/** Adapter for holding policies that take the shared facts array. */
final class MoneyDecisions
{
    public const BUSY = NativeDecisions::BUSY;

    public static function run(MoneyRequest $request, \Closure $decide): MoneyResult
    {
        return NativeDecisions::run($request, static fn (MoneyLockContext $locks) => $decide($locks->facts()));
    }

    public static function contention(\Throwable $error): bool { return NativeDecisions::contention($error); }
    public static function queued(int $serverId, int $limit, array $kinds): array { return NativeDecisions::queued($serverId, $limit, $kinds); }
    public static function done(object $work): bool { return NativeDecisions::done($work); }
}
