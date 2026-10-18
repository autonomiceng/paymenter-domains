<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\{Eloquent\Builder, Query\Builder as Query};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\RefundCandidates as Selection;
/** Presentation adapter; eligibility remains in the engine's locked proof writers. */
final class RefundCandidates
{
    public static function query(Builder|Query $q, string $prefix = ''): Builder|Query { return Selection::query($q, $prefix); }
    public static function matches(Operation $op, array $funding): bool { return Selection::matches($op, $funding); }
}
