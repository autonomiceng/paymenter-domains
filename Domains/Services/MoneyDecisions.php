<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\{DB,Schema};
/** One outer transaction under HoldingLocks per money decision. Contention rolls the whole transaction back and retries
 * once with fresh rows, then defers. The closure does no provider calls, notifications or after-commit dispatch, and lets
 * database errors through: a caught one would let later statements commit without the locks the database released. */
final class MoneyDecisions
{
    public const BUSY='The system was busy and nothing changed. Try again.';
    private static bool $running=false;
    public static function run(MoneyRequest $request, \Closure $decide): MoneyResult
    {
        if (self::$running || DB::transactionLevel() !== 0) { throw new \LogicException('Money decisions do not nest.'); }
        self::$running = true;
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $value = DB::transaction(static fn () => $decide(match ($request->scope) {
                        MoneyScope::Full => HoldingLocks::take($request->seed, $request->staffId, released: $request->released),
                    }));
                    return new MoneyResult($value === null ? MoneyOutcome::NoChange : MoneyOutcome::Applied, $value);
                } catch (\Throwable $e) {
                    // DB::transaction issued ROLLBACK: a lock wait timeout may have undone only its statement.
                    if (!self::contention($e)) { return new MoneyResult(MoneyOutcome::Failed, error: $e); }
                }
            }
        } finally { self::$running = false; }
        if ($request->kind !== null) { self::enqueue($request); }
        return new MoneyResult(MoneyOutcome::Deferred);
    }
    /** SQLSTATE 40001 or driver 1213/1205, on the error or any previous cause. No message matching. */
    public static function contention(\Throwable $error): bool
    {
        for (; $error !== null; $error = $error->getPrevious()) {
            if ($error instanceof DeadlockException) { return true; }
            if ($error instanceof \PDOException && ((string) $error->getCode() === '40001' || ($error->errorInfo[0] ?? null) === '40001'
                || in_array((int) ($error->errorInfo[1] ?? 0), [1213, 1205], true))) { return true; }
        }
        return false;
    }
    /** Every deferral takes the account's next turn, so an item goes behind all work deferred before it. The revision only
     * guards acknowledgement. Two deferrals racing for one turn share it and fall back to id order. */
    private static function enqueue(MoneyRequest $r): void
    {
        try {
            $turn = (int) DB::table(MoneyWorkSchema::TABLE)->where('server_id', $r->seed->server_id)->max('turn') + 1;
            DB::table(MoneyWorkSchema::TABLE)->upsert([['server_id' => $r->seed->server_id, 'account_fingerprint' => $r->seed->account_fingerprint,
                'kind' => $r->kind, 'subject_id' => $r->subject, 'revision' => 1, 'turn' => $turn]], ['server_id', 'kind', 'subject_id'],
                ['account_fingerprint', 'turn', 'revision' => DB::raw('revision + 1')]);
        } catch (\Throwable $e) { throw new MoneyWorkUnqueued('A deferred money decision could not be queued.', 0, $e); }
    }
    /** Deferred work for one account, longest waiting since its last deferral first. Read without locks; nothing is held across a decision. */
    public static function queued(int $serverId, int $limit): array
    {
        if (!Schema::hasTable(MoneyWorkSchema::TABLE)) { return []; }
        return DB::table(MoneyWorkSchema::TABLE)->where('server_id', $serverId)->orderBy('turn')->orderBy('id')->limit($limit)->get()->all();
    }
    /** Only the revision that was run is removed; a deferral since then keeps the item queued. */
    public static function done(object $work): void
    { DB::table(MoneyWorkSchema::TABLE)->where('id', $work->id)->where('revision', $work->revision)->delete(); }
}
