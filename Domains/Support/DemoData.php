<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Support;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, ManagedPublicConfig, Operation, WatchName};
/** Decides whether a record is seeded demo data. Demo records keep their real status, Missing values and actions;
 * they only gain the Demo tag after their name. Markers are the ones the offline demo seed writes. */
final class DemoData
{
    public const TOOLTIP = 'Demo data. Seeded for testing. Nothing is sent to a registrar and no money moves.';
    public const BANNER = 'Test system. Records tagged Demo are seeded examples.';
    public const ACCOUNTS = ['enom' => 'beco-demo-enom', 'namesilo' => 'beco-demo-namesilo'];
    private const MARKER = 'offline_demo';
    public static function is(mixed $model): bool
    {
        return match (true) {
            $model instanceof Domain => ($model->snapshot[self::MARKER] ?? null) === true,
            $model instanceof Operation => ($model->policy[self::MARKER] ?? null) === true || $model->funding_basis === self::MARKER,
            $model instanceof ManagedPublicConfig => $model->reason === self::MARKER,
            $model instanceof Account => isset(self::ACCOUNTS[$model->registrar_key]) && $model->stable_identity === self::ACCOUNTS[$model->registrar_key],
            $model instanceof WatchName => ($model->registry?->facts[self::MARKER] ?? null) === true,
            default => false,
        };
    }
    /** True while a seeded demo registrar account exists. Accounts are few, so each is checked directly. */
    public static function present(): bool
    {
        return Account::query()->whereIn('registrar_key', array_keys(self::ACCOUNTS))->get()->contains(static fn (Account $a): bool => self::is($a));
    }
    public static function tag(bool $inline = false): HtmlString
    {
        return new HtmlString(view('domains::partials.demo-tag', ['inline' => $inline])->render());
    }
}
