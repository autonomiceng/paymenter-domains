<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\{DB,Log,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CatalogueAcceptance,CatalogueFreshness,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{CataloguePreview,EnomTestReconciliationReader};

/** Gets current costs for every accepted TLD of one account in one run: when staff click Sync prices, and once a day.
 * A TLD that fails keeps its last cost and is recorded; the other TLDs still sync. */
final class PriceSync
{
    public function __construct(private readonly AccountBindings $bindings, private readonly CatalogueSync $catalogue, private readonly Clock $clock) {}

    /** Enom calls one full sync costs: the TLD list, then details, registry fields and one price read per accepted term. */
    public static function calls(CatalogueAcceptance $accepted): int
    {
        $calls = 1;
        foreach ($accepted->endings as $row) {
            $calls += 2 + count($row['register'] ?? []) + count($row['renew'] ?? []) + count($row['transfer'] ?? []);
        }
        return $calls;
    }

    /** Enom calls this account can still send today. Null when unknown; live with a daily cap of 0 has none. */
    public static function callsLeft(AccountBinding $binding): ?int
    {
        try {
            $account = Account::where('server_id', $binding->serverId)->sole();
            if (EnomLive::deployment($binding)) {
                $cap = EnomLive::account($binding)['daily_call_cap'] ?? 0;
                if (!is_int($cap) || $cap < 1) { return 0; }
                $used = (int) DB::table(EnomLiveCallSchema::TABLE)->where(['account' => hash('sha256', (string) $account->stable_identity), 'day' => EnomTestCalls::day()])->value('used');
                return max(0, $cap - $used);
            }
            ['used' => $used, 'limit' => $limit] = EnomTestCalls::usage();
            return $used === null || $limit === null ? null : max(0, $limit - $used);
        } catch (\Throwable) { return null; }
    }

    /** One sync run: every accepted TLD, or the one ending given. Staff runs carry their review; the daily run has none.
     * @return array{synced:list<string>,failed:array<string,string>} */
    public function run(Account $account, Clock $clock, ?StaffSettingsReview $review, ?string $ending = null): array
    {
        $binding = CatalogueAcceptances::binding($account);
        if (!TestCatalogue::enabled($binding)) { throw new \DomainException('TEST catalogue reads are unavailable.'); }
        $accepted = CatalogueAcceptances::load($binding);
        if ($ending !== null && !isset($accepted->endings[$ending])) { throw new \DomainException('Catalogue ending is not accepted.'); }
        $endings = $ending === null ? array_keys($accepted->endings) : [$ending];
        if ($endings === []) { throw new \DomainException('Catalogue ending is not accepted.'); }
        $reader = $this->bindings->reader($binding->serverId);
        $calls = self::calls(new CatalogueAcceptance($accepted->binding, $accepted->revision, array_intersect_key($accepted->endings, array_flip($endings))));
        $context = new RequestContext($binding, $review ? RequestOrigin::Interactive : RequestOrigin::Background,
            $clock->now()->modify('+'.(20 + 5 * $calls).' seconds'), new RequestBudget(min(200, $calls + 10)), $clock, Runtime::limiter(), stopOnReadFailure: true);
        $listed = [];
        if ($reader instanceof CataloguePreview || $reader instanceof EnomTestReconciliationReader) {
            try { $listed = $reader->catalogue($context)->facts()['tlds'] ?? []; }
            catch (ReadDeferred|EnomTestCallLimit $error) { throw $error; }
            catch (\Throwable $error) { throw new CatalogueImportFailure(ReadFailure::code($error)); }
        }
        $failed = [];
        foreach ($endings as $e) { if (!in_array($e, $listed, true)) { $failed[$e] = 'catalogue_evidence_missing'; } }
        $read = array_values(array_diff($endings, array_keys($failed)));
        $synced = [];
        if ($read !== []) {
            $result = $this->catalogue->refresh($binding->serverId, $context, new CatalogueFreshness, $read, $review, $review === null);
            foreach ($read as $e) {
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
        return ['synced' => $synced, 'failed' => $failed];
    }

    /** Once a day per account, inside its daily Enom call cap. A day the cap can't cover is skipped and logged, not retried. */
    public function daily(Account $account): void
    {
        OperationClaims::outside();
        $binding = CatalogueAcceptances::binding($account);
        if (!TestCatalogue::enabled($binding) || !Schema::hasTable('domains_observation_queues')) { return; }
        $accepted = CatalogueAcceptances::load($binding);
        if ($accepted->endings === [] || !$this->claim($account)) { return; }
        $context = ['server_id' => (int) $account->server_id];
        $needed = self::calls($accepted); $left = self::callsLeft($binding);
        if ($left === null || $left < $needed) {
            Log::info('Domains daily price sync skipped.', [...$context, 'reason' => 'call_cap', 'needed' => $needed, 'left' => $left]);
            return;
        }
        try {
            $result = $this->run($account, $this->clock, null);
            Log::info('Domains daily price sync finished.', [...$context, 'synced' => count($result['synced']), 'failed' => array_keys($result['failed'])]);
        } catch (\Throwable $error) {
            Log::warning('Domains daily price sync failed.', [...$context, 'code' => ReadFailure::code($error)]);
        }
    }

    /** Claims today's run under the account's queue row lock. Scheduled catalogue refresh never runs for these accounts,
     * so its due time is free to mark the daily sync. */
    private function claim(Account $account): bool
    {
        return DB::transaction(function () use ($account): bool {
            $q = DB::table('domains_observation_queues')->where('server_id', $account->server_id)->where('account_fingerprint', $account->account_fingerprint);
            $row = (clone $q)->lockForUpdate()->first(); $now = $this->clock->now()->getTimestamp();
            if (!$row || (int) $row->catalogue_due > $now) { return false; }
            $q->update(['catalogue_due' => $now + 86400]);
            return true;
        });
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
