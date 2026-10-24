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
    public const TEST_TOOLTIP = 'Test registration. Made in the registrar\'s test environment. It is not a real domain and no money moves.';
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
    /** A domain on a paused demo registrar account: nothing syncs, so its synced values are Missing. Pass the account when it is loaded. */
    public static function syncPaused(Domain $domain, ?Account $account = null): bool
    {
        if (!self::is($domain)) { return false; }
        $account ??= Account::where('server_id', $domain->server_id)->where('registrar_key', $domain->registrar_key)
            ->where('account_fingerprint', $domain->account_fingerprint)->first();
        return $account !== null && self::paused($account);
    }
    /** The one demo-paused rule, for client and staff pages: the demo runtime skips the demo Enom account while Enom runs
     * in Test mode or real Enom writes are on. */
    public static function paused(Account $account): bool
    {
        return self::is($account) && $account->registrar_key === 'enom' && (config('domains.enom_identity.environment') === 'test'
            || config('domains.enom_writes.registration') === true || config('domains.enom_writes.renewal') === true);
    }
    public static function tag(bool $inline = false, bool $test = false): HtmlString
    {
        return new HtmlString(view('domains::partials.demo-tag', ['inline' => $inline, 'test' => $test])->render());
    }
    /** Tag after a customer domain name: Demo for seeded data, else Test for a registrar test-mode registration, else nothing. */
    public static function label(bool $demo, bool $test, bool $inline = false): HtmlString
    {
        return $demo || $test ? self::tag($inline, !$demo) : new HtmlString('');
    }
}
