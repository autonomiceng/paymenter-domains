<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\{DB,Log,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CatalogueAcceptance,CatalogueFreshness,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{CataloguePreview,EnomTestReconciliationReader};

/** Gets current costs for every accepted TLD of one account in one run: when staff click Sync prices, and once a day.
 * A TLD that fails keeps its last cost and is recorded; the other TLDs still sync. */
final class PriceSync
{
    /** Most calls one run reads, below our per-minute read limit. TLDs past it wait for the next run. */
    public const BATCH_CALLS = 40;
    /** A daily run that left TLDs for later picks them up this many seconds later. */
    public const RESUME_SECONDS = 120;

    public function __construct(private readonly AccountBindings $bindings, private readonly CatalogueSync $catalogue, private readonly Clock $clock,
        private readonly int $batchCalls = self::BATCH_CALLS) {}

    /** Enom calls one full sync costs: the TLD list, then details, registry fields and one price read per accepted term. */
    public static function calls(CatalogueAcceptance $accepted): int
    {
        $calls = 1;
        foreach ($accepted->endings as $row) { $calls += self::endingCalls($row); }
        return $calls;
    }

    private static function endingCalls(array $row): int
    { return 2 + count($row['register'] ?? []) + count($row['renew'] ?? []) + count($row['transfer'] ?? []); }

    /** Calls to sync these endings in batches: each batch reads the TLD list again. */
    public static function batchedCalls(CatalogueAcceptance $accepted, array $endings, int $max = self::BATCH_CALLS): int
    {
        $calls = 0;
        while ($endings !== []) {
            [$batch, $endings] = self::batch($accepted, $endings, $max);
            $calls += self::calls(new CatalogueAcceptance($accepted->binding, $accepted->revision, array_intersect_key($accepted->endings, array_flip($batch))));
        }
        return $calls;
    }

    /** Splits endings into this run's batch (at most $max calls, at least one ending) and the rest.
     * @return array{0:list<string>,1:list<string>} */
    public static function batch(CatalogueAcceptance $accepted, array $endings, int $max = self::BATCH_CALLS): array
    {
        $calls = 0;
        foreach (array_values($endings) as $i => $e) {
            $calls += self::endingCalls($accepted->endings[$e]);
            if ($i > 0 && $calls > $max) { return [array_slice($endings, 0, $i), array_slice($endings, $i)]; }
        }
        return [array_values($endings), []];
    }

    /** Calls this login has sent and our daily cap for it (rolling 24 hours for TEST, UTC day for LIVE). The cap is our own guard against a runaway loop,
     * not a registrar limit. Null when unknown; live with no valid cap gets 0. */
    public static function callsToday(AccountBinding $binding): ?array
    {
        try {
            $account = Account::where('server_id', $binding->serverId)->sole();
            if (EnomLive::deployment($binding)) {
                $cap = EnomLive::account($binding)['daily_call_cap'] ?? 0;
                $used = (int) DB::table(EnomLiveCallSchema::TABLE)->where(['account' => hash('sha256', (string) $account->stable_identity), 'day' => EnomTestCalls::day()])->value('used');
                return ['used' => $used, 'limit' => is_int($cap) && $cap > 0 ? $cap : 0];
            }
            if (($uid = EnomTestSendLimit::login($account)) === null) { return null; }
            $usage = EnomTestSendLimit::usage($uid);
            if ($usage['day'] === null || $usage['day_limit'] === null || $usage['minute_limit'] === null) { return null; }
            return ['used' => $usage['day'], 'limit' => $usage['minute_limit'] === 0 ? 0 : $usage['day_limit']];
        } catch (\Throwable) { return null; }
    }

    /** Calls left under our daily cap (rolling 24 hours for TEST). Null when unknown. */
    public static function callsLeft(AccountBinding $binding): ?int
    { $today = self::callsToday($binding); return $today === null ? null : max(0, $today['limit'] - $today['used']); }

    /** One sync run: every accepted TLD, the one ending given, or the listed endings. Staff runs carry their review;
     * the daily run has none. One run reads at most one batch, oldest-synced TLDs first, so it stays under our
     * per-minute read limit and its request budget; the TLDs past the batch are returned as deferred for the next run.
     * @return array{synced:list<string>,failed:array<string,string>,deferred:list<string>} */
    public function run(Account $account, Clock $clock, ?StaffSettingsReview $review, ?string $ending = null, ?array $only = null): array
    {
        $binding = CatalogueAcceptances::binding($account);
        if (!TestCatalogue::enabled($binding)) { throw new \DomainException('TEST catalogue reads are unavailable.'); }
        $accepted = CatalogueAcceptances::load($binding);
        if ($ending !== null && !isset($accepted->endings[$ending])) { throw new \DomainException('Catalogue ending is not accepted.'); }
        $endings = $ending !== null ? [$ending] : ($only === null ? array_keys($accepted->endings) : array_values(array_intersect(array_keys($accepted->endings), $only)));
        if ($endings === []) { throw new \DomainException('Catalogue ending is not accepted.'); }
        $last = Price::where('server_id', $binding->serverId)->whereIn('tld', $endings)->groupBy('tld')->selectRaw('tld, max(observed_at) as at')->pluck('at', 'tld');
        usort($endings, fn (string $a, string $b) => strcmp((string) ($last[$a] ?? ''), (string) ($last[$b] ?? '')));
        $reader = $this->bindings->reader($binding->serverId);
        $context = fn (int $calls) => new RequestContext($binding, $review ? RequestOrigin::Interactive : RequestOrigin::Background,
            $clock->now()->modify('+'.(20 + 5 * $calls).' seconds'), new RequestBudget($calls + 10), $clock, Runtime::limiter(), stopOnReadFailure: true);
        $listed = [];
        if ($reader instanceof CataloguePreview || $reader instanceof EnomTestReconciliationReader) {
            try { $listed = $reader->catalogue($context(1))->facts()['tlds'] ?? []; }
            catch (ReadDeferred|EnomTestCallLimit $error) { throw $error; }
            catch (\Throwable $error) { throw new CatalogueImportFailure(ReadFailure::code($error)); }
        }
        $failed = [];
        foreach ($endings as $e) { if (!in_array($e, $listed, true)) { $failed[$e] = 'catalogue_evidence_missing'; } }
        $read = array_values(array_diff($endings, array_keys($failed)));
        [$batch, $deferred] = self::batch($accepted, $read, $this->batchCalls);
        $synced = [];
        if ($batch !== []) {
            $calls = self::calls(new CatalogueAcceptance($accepted->binding, $accepted->revision, array_intersect_key($accepted->endings, array_flip($batch)))) - 1;
            $result = $this->catalogue->refresh($binding->serverId, $context($calls), new CatalogueFreshness, $batch, $review, $review === null);
            foreach ($batch as $e) {
                if (in_array('.'.$e, $result['failed'], true) || in_array('.'.$e, $result['quarantined'] ?? [], true)) {
                    $failed[$e] = $result['failure_codes']['.'.$e] ?? 'catalogue_import_failed';
                } else { $synced[] = $e; }
            }
        }
        foreach ($synced as $e) {
            app(SellerPolicies::class)->initialize($e);
            // Checkout takes the new cost; only lines that can no longer sell (no cost, below cost) are removed.
            TestCatalogue::withdrawCarts($binding, $e, true);
        }
        // Staff see failures in the result; the daily run keeps them where the TLD list shows the last sync failure.
        if ($review === null) { foreach ($failed as $e => $code) { $this->failure($account, $e, $code, $clock); } }
        $review?->input([...($ending === null ? [] : ['ending' => $ending]), 'endings' => $synced]);
        if ($synced === []) { throw new CatalogueImportFailure(reset($failed) ?: 'catalogue_import_failed'); }
        if ($ending !== null && $failed !== []) { throw new CatalogueImportFailure($failed[$ending]); }
        return ['synced' => $synced, 'failed' => $failed, 'deferred' => $deferred];
    }

    /** Once a day per account, inside our daily call cap for it. A day the cap can't cover is skipped and logged, not retried. */
    public function daily(Account $account): void
    {
        OperationClaims::outside();
        $binding = CatalogueAcceptances::binding($account);
        if (!TestCatalogue::enabled($binding) || !Schema::hasTable('domains_observation_queues')) { return; }
        $accepted = CatalogueAcceptances::load($binding);
        if ($accepted->endings === [] || ($claim = $this->claim($account)) === null) { return; }
        [$since, $resume] = $claim;
        $endings = array_keys($accepted->endings);
        if ($resume) {
            // A resumed run reads only the TLDs this run has not synced yet.
            $endings = array_values(array_filter($endings, fn (string $e) => !Price::where('server_id', $account->server_id)
                ->where('tld', $e)->where('observed_at', '>=', date('Y-m-d H:i:s', $since))->exists()));
            if ($endings === []) { return; }
        }
        $context = ['server_id' => (int) $account->server_id];
        $needed = self::batchedCalls($accepted, $endings, $this->batchCalls);
        $left = self::callsLeft($binding);
        if ($left === null || $left < $needed) {
            Log::info('Domains daily price sync skipped.', [...$context, 'reason' => 'call_cap', 'needed' => $needed, 'left' => $left]);
            return;
        }
        try {
            $result = $this->run($account, $this->clock, null, null, $endings);
            if ($result['deferred'] !== []) { $this->resumeSoon($account); }
            Log::info('Domains daily price sync finished.', [...$context, 'synced' => count($result['synced']), 'failed' => array_keys($result['failed']), 'deferred' => $result['deferred']]);
        } catch (ReadDeferred $error) {
            $this->resumeSoon($account);
            Log::info('Domains daily price sync deferred.', [...$context, 'code' => ReadFailure::code($error)]);
        } catch (\Throwable $error) {
            Log::warning('Domains daily price sync failed.', [...$context, 'code' => ReadFailure::code($error)]);
        }
    }

    /** Claims a run under the account's queue row lock: [run start, resuming]. Scheduled catalogue refresh never runs
     * for these accounts, so its due time marks the next run and its cursor the start of the current day's run. */
    private function claim(Account $account): ?array
    {
        return DB::transaction(function () use ($account): ?array {
            $q = DB::table('domains_observation_queues')->where('server_id', $account->server_id)->where('account_fingerprint', $account->account_fingerprint);
            $row = (clone $q)->lockForUpdate()->first(); $now = $this->clock->now()->getTimestamp();
            if (!$row || (int) $row->catalogue_due > $now) { return null; }
            $start = (int) $row->catalogue_cursor;
            if ($start > $now - 86400 && $start <= $now) { $q->update(['catalogue_due' => $start + 86400]); return [$start, true]; }
            $q->update(['catalogue_due' => $now + 86400, 'catalogue_cursor' => $now]);
            return [$now, false];
        });
    }

    /** A throttled run picks up its remaining TLDs shortly, inside the same day. */
    private function resumeSoon(Account $account): void
    {
        DB::table('domains_observation_queues')->where('server_id', $account->server_id)->where('account_fingerprint', $account->account_fingerprint)
            ->update(['catalogue_due' => $this->clock->now()->getTimestamp() + self::RESUME_SECONDS]);
    }

    private function failure(Account $account, string $ending, string $code, Clock $clock): void
    {
        try {
            DB::transaction(function () use ($account, $ending, $code, $clock): void {
                $q = DB::table('domains_observation_queues')->where('server_id', $account->server_id)->where('account_fingerprint', $account->account_fingerprint);
                $row = (clone $q)->lockForUpdate()->first(); if (!$row) { return; }
                $audit = json_decode($row->catalogue_audit ?? '[]', true, 8, JSON_THROW_ON_ERROR); if (!is_array($audit)) { $audit = []; }
                $audit[] = ['code' => ReadFailure::safe($code), 'server_id' => (int) $account->server_id, 'account_fingerprint' => $account->account_fingerprint,
                    'tld' => $ending, 'at' => $clock->now()->format(DATE_ATOM)];
                $q->update(['catalogue_audit' => json_encode(array_slice($audit, -50), JSON_THROW_ON_ERROR)]);
            });
        } catch (\Throwable) { /* The sync result does not depend on saving the diagnostic. */ }
    }
}
