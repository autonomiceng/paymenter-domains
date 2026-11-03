<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;

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

    /** A batch only needs room for itself, never the entire list. Leave 70% of our shared day for other work. */
    public static function backgroundLeft(AccountBinding $binding, Clock $clock): int
    {
        $today = self::callsToday($binding);
        if ($today === null) { return 0; }
        $day = max(0, intdiv($today['limit'] * 3, 10) - $today['used']);
        $minute = DB::table('domains_read_budgets')->where('server_id', $binding->serverId)->first();
        $used = $minute && (int) $minute->window_at > (int) $clock->now()->format('Uu') - 60000000 ? (int) $minute->used : 0;
        // One maximum-term TLD needs 14 reads. Twenty-five lets that TLD finish inside one minute.
        return min($day, max(0, 25 - $used));
    }

    /** One sync run: every accepted TLD, the one ending given, or the listed endings. Staff runs carry their review;
     * the daily run has none. One run reads at most one batch, oldest-synced TLDs first, so it stays under our
     * per-minute read limit and its request budget; the TLDs past the batch are returned as deferred for the next run.
     * @return array{synced:list<string>,failed:array<string,string>,deferred:list<string>} */
    public function run(Account $account, Clock $clock, ?StaffSettingsReview $review, ?string $ending = null, ?array $only = null, ?int $generation = null): array
    {
        $generation=UpgradeGate::legacy($generation);
        $binding = CatalogueAcceptances::binding($account);
        if (!TestCatalogue::enabled($binding)) { throw new \DomainException('TEST catalogue reads are unavailable.'); }
        if ($review === null) { SellAutomation::refresh($account, $clock->now()); }
        $accepted = CatalogueAcceptances::load($binding);
        if ($review === null) { $accepted = SellAutomation::priceReads($account, $accepted, $clock->now()); }
        if ($ending !== null && !isset($accepted->endings[$ending])) { throw new \DomainException('Catalogue ending is not accepted.'); }
        $endings = $ending !== null ? [$ending] : ($only === null ? array_keys($accepted->endings) : array_values(array_intersect(array_keys($accepted->endings), $only)));
        if ($endings === []) { throw new \DomainException('Catalogue ending is not accepted.'); }
        $last = Price::where('server_id', $binding->serverId)->whereIn('tld', $endings)->groupBy('tld')->selectRaw('tld, max(observed_at) as at')->pluck('at', 'tld');
        usort($endings, fn (string $a, string $b) => strcmp((string) ($last[$a] ?? ''), (string) ($last[$b] ?? '')));
        $automatic = $review === null && SellRule::saved($binding) !== null;
        $reader = $this->bindings->reader($binding->serverId);
        $context = fn (int $calls) => new RequestContext($binding, $review ? RequestOrigin::Interactive : RequestOrigin::Background,
            $clock->now()->modify('+'.(20 + 5 * $calls).' seconds'), new RequestBudget($calls + 10), $clock, $automatic ? new PriceReadBudget($clock) : Runtime::limiter(), stopOnReadFailure: true);
        $fill = PriceFill::last($binding);
        $listed = $automatic && $fill?->filled_at !== null && (int) $fill->filled_at > $clock->now()->getTimestamp() - PriceCatalogue::MAX_COST_AGE_SECONDS
            ? SellRule::rows($binding)->pluck('tld')->all() : null;
        if ($listed === null && ($reader instanceof CataloguePreview || $reader instanceof EnomTestReconciliationReader)) {
            try { $listed = $reader->catalogue($context(1))->facts()['tlds'] ?? []; }
            catch (ReadDeferred|EnomTestCallLimit $error) { throw $error; }
            catch (\Throwable $error) { throw new CatalogueImportFailure(ReadFailure::code($error)); }
        }
        $listed ??= [];
        $failed = [];
        foreach ($endings as $e) { if (!in_array($e, $listed, true)) { $failed[$e] = 'catalogue_evidence_missing'; } }
        $read = array_values(array_diff($endings, array_keys($failed)));
        $capacity = $automatic ? min($this->batchCalls, self::backgroundLeft($binding, $clock)) : $this->batchCalls;
        if ($read !== [] && self::endingCalls($accepted->endings[$read[0]]) > $capacity) { throw new ReadDeferred('Our price read allowance is unavailable.'); }
        [$batch, $deferred] = self::batch($accepted, $read, max(1, $capacity));
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
        // Manual batches resolve a retained failure once every accepted term has current cost evidence.
        if ($review !== null && $ending === null && $only === null && $failed === [] && ($deferred === [] || $this->currentCosts($accepted, $clock))) { $this->outcome($account, null,$generation); }
        $review?->input([...($ending === null ? [] : ['ending' => $ending]), 'endings' => $synced]);
        if ($synced === []) { throw new CatalogueImportFailure(reset($failed) ?: 'catalogue_import_failed'); }
        if ($ending !== null && $failed !== []) { throw new CatalogueImportFailure($failed[$ending]); }
        return ['synced' => $synced, 'failed' => $failed, 'deferred' => $deferred];
    }

    /** Read existing evidence across manual batches; no cursor or provider read is needed. */
    private function currentCosts(CatalogueAcceptance $accepted, Clock $clock): bool
    {
        try {
            $prices = app(PriceCatalogue::class); $binding = $accepted->binding;
            foreach ($accepted->endings as $ending => $row) {
                foreach (['register', 'renew', 'transfer'] as $action) {
                    foreach ($row[$action] ?? [] as $term) {
                        $prices->current($binding->serverId, $binding->fingerprint, $ending, $action, $term, 'USD', $clock->now());
                    }
                }
            }
            return true;
        } catch (\DomainException|\InvalidArgumentException|\Illuminate\Database\Eloquent\ModelNotFoundException) { return false; }
    }

    /** Once a day per account, inside our daily call cap. The next scheduled run retries failures before staff are asked. */
    public function daily(Account $account): void
    {
        OperationClaims::outside();
        $generation=UpgradeGate::legacy();
        $binding = CatalogueAcceptances::binding($account);
        if (!TestCatalogue::enabled($binding) || !Schema::hasTable('domains_observation_queues')) { return; }
        SellAutomation::refresh($account, $this->clock->now());
        $accepted = CatalogueAcceptances::load($binding);
        $accepted = SellAutomation::priceReads($account, $accepted, $this->clock->now());
        if ($accepted->endings === [] || ($claim = $this->claim($account,$generation)) === null) { return; }
        [$since, $resume] = $claim;
        $endings = array_keys($accepted->endings);
        if ($resume) {
            // A resumed run reads only the TLDs this run has not synced yet.
            $endings = array_values(array_filter($endings, fn (string $e) => !Price::where('server_id', $account->server_id)
                ->where('tld', $e)->where('observed_at', '>=', date('Y-m-d H:i:s', $since))->exists()));
            if ($endings === []) { return; }
        }
        $context = ['server_id' => (int) $account->server_id];
        $skipped = [];
        if (SellRule::saved($binding) === null) {
            $needed = self::batchedCalls($accepted, $endings, $this->batchCalls);
            $left = self::callsLeft($binding);
            if ($left !== null && $left < $needed) {
                // A long list must not stop the TLDs already on sale from staying fresh; new ones wait for a day the cap covers.
                $priced = Price::where('server_id', $account->server_id)->whereIn('tld', $endings)->distinct()->pluck('tld')->all();
                $kept = array_values(array_intersect($endings, $priced));
                if ($kept !== [] && ($fits = self::batchedCalls($accepted, $kept, $this->batchCalls)) <= $left) { $skipped = array_values(array_diff($endings, $kept)); $endings = $kept; $needed = $fits; }
            }
            if ($left === null || $left < $needed) {
                $this->outcome($account, 'catalogue_call_capacity',$generation);
                Log::info('Domains daily price sync skipped.', [...$context, 'reason' => 'call_cap', 'needed' => $needed, 'left' => $left]);
                return;
            }
        }
        try {
            $result = $this->run($account, $this->clock, null, null, $endings,$generation);
            if ($result['failed'] !== [] || $skipped !== []) {
                $this->outcome($account, $skipped !== [] ? 'catalogue_call_capacity' : reset($result['failed']),$generation);
            } elseif ($result['deferred'] === []) { $this->outcome($account, null,$generation); }
            if ($result['deferred'] !== []) { $this->resumeSoon($account,$generation); }
            Log::info('Domains daily price sync finished.', [...$context, 'synced' => count($result['synced']), 'failed' => array_keys($result['failed']), 'deferred' => $result['deferred']]);
        } catch (ReadDeferred $error) {
            $this->outcome($account, 'catalogue_call_capacity',$generation);
            $this->resumeSoon($account,$generation);
            Log::info('Domains daily price sync deferred.', [...$context, 'code' => ReadFailure::code($error)]);
        } catch (\Throwable $error) {
            $this->outcome($account, $error instanceof EnomTestCallLimit ? 'catalogue_call_capacity' : ReadFailure::code($error),$generation);
            Log::warning('Domains daily price sync failed.', [...$context, 'code' => ReadFailure::code($error)]);
        }
    }

    /** Claims a run under the account's queue row lock: [run start, resuming]. Scheduled catalogue refresh never runs
     * for these accounts, so its due time marks the next run and its cursor the start of the current day's run. */
    private function claim(Account $account,int $generation): ?array
    {
        return DB::transaction(function () use ($account,$generation): ?array {
            UpgradeGate::lockLegacy($generation,(int)$account->server_id);
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
    private function resumeSoon(Account $account,int $generation): void
    {
        DB::transaction(function()use($account,$generation):void {
            UpgradeGate::lockLegacy($generation,(int)$account->server_id);
            DB::table('domains_observation_queues')->where('server_id', $account->server_id)->where('account_fingerprint', $account->account_fingerprint)
                ->update(['catalogue_due' => $this->clock->now()->getTimestamp() + self::RESUME_SECONDS]);
        });
    }

    /** Retain one account episode, including failures before a TLD can be read. No new hold stops automatic recovery. */
    private function outcome(Account $account, ?string $code,int $generation): void
    {
        try {
            $event = DB::transaction(function () use ($account, $code,$generation): ?array {
                UpgradeGate::lockLegacy($generation,(int)$account->server_id);
                $q = DB::table('domains_observation_queues')->where('server_id', $account->server_id)->where('account_fingerprint', $account->account_fingerprint);
                $row = (clone $q)->lockForUpdate()->first(); if (!$row) { return null; }
                try { $audit = json_decode($row->catalogue_audit ?? '[]', true, 8, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { $audit = []; }
                if (!is_array($audit)) { $audit = []; }
                $prior = null;
                foreach (array_reverse($audit) as $entry) { if (($entry['kind'] ?? null) === 'price_sync') { $prior = $entry; break; } }
                if ($code === null && (!$prior || $prior['state'] === 'recovered')) { return null; }
                $active = $prior && $prior['state'] !== 'recovered';
                $at = $this->clock->now()->format(DATE_ATOM);
                // A batch continuation is not another scheduled recovery attempt.
                $retried = $active && $this->clock->now()->getTimestamp() >= strtotime($prior['first_at']) + 86400 - 3600;
                $entry = ['kind' => 'price_sync', 'state' => $code === null ? 'recovered' : ($retried ? 'failed' : 'retrying'),
                    'code' => $code === null ? $prior['code'] : ReadFailure::safe($code), 'server_id' => (int) $account->server_id,
                    'account_fingerprint' => $account->account_fingerprint, 'at' => $at, 'first_at' => $active ? $prior['first_at'] : $at];
                $audit[] = $entry;
                $q->update(['catalogue_audit' => json_encode(array_slice($audit, -50), JSON_THROW_ON_ERROR)]);
                return $entry['state'] === 'failed' ? $entry : null;
            });
            if ($event) {
                // The scheduler already retried; only a person can fix credentials or change our call cap.
                // Same line as the TLD list, which shows what to fix and when sales stop.
                $cause = \Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncCause($event['code']);
                $name = (string) (\App\Models\Server::whereKey($account->server_id)->value('name') ?: 'Registrar account');
                \Paymenter\Extensions\Servers\Domains\Admin\Support\StaffAlert::send('Price sync keeps failing', $name.' (account '.$account->server_id.'): '.$cause.'.',
                    'filament.admin.resources.domain-tlds.index', deliveryKey: 'price-sync:'.$account->account_fingerprint.':'.$event['first_at']);
            }
        } catch (\Throwable) { Log::warning('Domains price sync diagnostic could not be saved.', ['server_id' => (int) $account->server_id]); }
    }
}
