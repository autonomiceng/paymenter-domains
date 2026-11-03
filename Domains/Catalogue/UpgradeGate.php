<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\{Server, Setting, User, Role};
use Illuminate\Support\Facades\{DB, Auth, Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{OfferSchema, StaffSettingsReview, StorefrontCatalogueCache};
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances;

/** Explicit offline/maintenance seam, never called by installation or runtime bootstrap.
 * External maintenance must stop/drain old executables and financial writers before capture.
 * All callers and the worker move together through the checked activation method. */
final class UpgradeGate
{
    public const KEY = 'domains_offer_upgrade';

    private static function query(): \Illuminate\Database\Eloquent\Builder
    { return Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key', self::KEY); }

    private static function state(?\Illuminate\Support\Collection $rows = null): array
    {
        // A transaction may already own an older consistent snapshot. Only a locking read is current.
        $rows ??= DB::transactionLevel() > 0 ? self::query()->lockForUpdate()->get() : self::query()->get();
        if ($rows->isEmpty()) { return ['state' => 'legacy', 'generation' => 0]; }
        if ($rows->count() !== 1 || !$rows[0]->encrypted || $rows[0]->type !== 'string') {
            throw new \DomainException('Catalogue upgrade state is invalid; keep maintenance active.');
        }
        try { $state = json_decode($rows[0]->value, true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \DomainException('Catalogue upgrade state is invalid; keep maintenance active.'); }
        if (!is_array($state) || !in_array($state['state'] ?? null, ['legacy', 'paused', 'active'], true)
            || !is_int($state['generation'] ?? null) || $state['generation'] < 1) {
            throw new \DomainException('Catalogue upgrade state is invalid; keep maintenance active.');
        }
        if ($state['state'] === 'active' && (!preg_match('/^[a-f0-9]{64}$/D', $state['contract'] ?? '') || !preg_match('/^[a-f0-9]{64}$/D', $state['digest'] ?? ''))) { throw new \DomainException('Invalid active catalogue contract.'); }
        return $state;
    }

    /** Advisory mode selection never takes the gate ahead of a caller's native/account prefix. */
    public static function routeState(): array { return self::state(self::query()->get()); }
    public static function active(int $generation): void
    {
        $s = self::state();
        if ($s['state'] !== 'active' || $s['generation'] !== $generation) { throw new \DomainException('Catalogue active generation changed.'); }
    }
    public static function generation(): int { return self::state()['generation']; }

    /** Call before legacy reads, and again under their existing server/account mutex before saves.
     * An old read remains fenced even after a pre-activation rollback resumes legacy work. */
    public static function legacy(?int $expectedGeneration = null): int
    {
        $state = self::state();
        if ($state['state'] !== 'legacy' || $expectedGeneration !== null && $state['generation'] !== $expectedGeneration) {
            throw new \DomainException('Catalogue upgrade paused or superseded this legacy work.');
        }
        return $state['generation'];
    }

    /** Caller owns the server/account mutex; every conversion transaction joins the same window. */
    public static function paused(int $generation): void
    {
        if (DB::transactionLevel() === 0) { throw new \LogicException('Conversion window check requires its mutex.'); }
        $state = self::state();
        if ($state['state'] !== 'paused' || $state['generation'] !== $generation) {
            throw new \DomainException('Catalogue upgrade requires the same paused maintenance window.');
        }
    }

    /** Shared prefix for writers that did not already own a native/server/account mutex. */
    public static function lockLegacy(?int $generation = null, ?int $serverId = null): int
    {
        if (DB::transactionLevel() === 0) { throw new \LogicException('Legacy write mutex requires a transaction.'); }
        $servers = Server::where('extension', 'Domains'); $accounts = Account::query();
        if ($serverId !== null) { $servers->whereKey($serverId); $accounts->where('server_id', $serverId); }
        $servers->orderBy('id')->lockForUpdate()->get();
        $accounts->orderBy('server_id')->lockForUpdate()->get();
        return self::legacy($generation);
    }

    public function pause(): int { return $this->transition('legacy', 'paused'); }
    public function resume(): int { return $this->transition('paused', 'legacy'); }

    private function transition(string $from, string $to): int
    {
        self::outside();
        return DB::transaction(static function () use ($from, $to): int {
            // Same server/account prefix as retained writers. No native, offer or price writes here.
            Server::where('extension', 'Domains')->orderBy('id')->lockForUpdate()->get();
            Account::orderBy('server_id')->lockForUpdate()->get();
            // Keep the rows from this current read, including the absent-row decision. The shared
            // server/account prefix serializes first creation; the setting range is locked as well.
            $rows = self::query()->lockForUpdate()->get();
            $state = self::state($rows);
            if ($state['state'] !== $from) { throw new \DomainException('Catalogue upgrade state changed.'); }
            $row = $rows->first() ?? new Setting(['key' => self::KEY, 'settingable_type' => null, 'settingable_id' => null]);
            $row->encrypted = true; $row->type = 'string';
            $row->value = json_encode(['state' => $to, 'generation' => $state['generation'] + 1], JSON_THROW_ON_ERROR);
            $row->save();
            return $state['generation'] + 1;
        });
    }

    private static function outside(): void
    { if (DB::transactionLevel() !== 0) { throw new \LogicException('Catalogue upgrade checks must run outside locks.'); } }

    private function window(?array $evidence = null): int
    {
        self::outside(); $state = self::state();
        if ($state['state'] !== 'paused' || $evidence !== null && ($evidence['generation'] ?? null) !== $state['generation']) {
            throw new \DomainException('Catalogue upgrade requires the same paused maintenance window.');
        }
        if ($evidence !== null && ($evidence['accounts'] ?? null) !== $this->accounts()) {
            throw new \DomainException('Catalogue upgrade account bindings changed.');
        }
        return $state['generation'];
    }

    /** Bounded digest of the entire binding set, including disabled accounts; no caller-chosen subset. */
    private function accounts(): array
    {
        $hash = hash_init('sha256'); $count = 0;
        Account::orderBy('server_id')->when(UpgradeComparison::locking(), fn($q) => $q->lockForUpdate())->chunk(250, static function ($accounts) use ($hash, &$count): void {
            foreach ($accounts as $account) {
                $value = $account->getRawOriginal();
                foreach (OfferSchema::COLUMNS['domains_accounts'] ?? [] as $column => $_) { unset($value[$column]); }
                $server = UpgradeComparison::read(DB::table((new Server)->getTable()))->where('id', $account->server_id)->firstOrFail();
                $settings = UpgradeComparison::read(DB::table('settings'))->where('settingable_type', (new Server)->getMorphClass())
                    ->where('settingable_id', $account->server_id)->orderBy('id')->get();
                hash_update($hash, json_encode([$value, $server, $settings], JSON_THROW_ON_ERROR)."\n"); $count++;
            }
        });
        return ['count' => $count, 'sha256' => hash_final($hash)];
    }

    /** Never clears a claim or assumes an expired lease proves a process has stopped. */
    private function drained(): void
    {
        if (UpgradeComparison::read(DB::table('domains_observation_queues'))->whereNotNull('catalogue_claim')->orWhereNotNull('offer_claim')->exists()
            || UpgradeComparison::read(DB::table('domains_observation_work'))->whereNotNull('claim')->exists()) {
            throw new \DomainException('Catalogue upgrade requires drained catalogue and observation claims.');
        }
        UpgradeComparison::read(DB::table('domains_read_budgets'))->orderBy('id')->chunk(250, static function ($rows): void {
            foreach ($rows as $row) {
                if (json_decode($row->leases, true, 32, JSON_THROW_ON_ERROR) !== []) {
                    throw new \DomainException('Catalogue upgrade requires released read reservations.');
                }
            }
        });
    }

    public function capture(): array
    {
        $generation = $this->window(); $this->drained();
        $evidence = ['generation' => $generation, 'accounts' => $this->accounts(), 'before' => UpgradeComparison::capture()];
        $this->window($evidence); $this->drained();
        return $evidence;
    }

    public function batch(AccountBinding $binding, array $evidence, int $limit = 100): array
    {
        $this->window($evidence); $this->drained();
        $result = (new UpgradeConversion)->batch($binding, $limit, $evidence['generation']);
        $this->window($evidence);
        return $result;
    }

    /** Report only. A compatible conversion is necessary, never sufficient, for activation.
     * Compare global obligations once, rather than once per account. */
    public function check(array $evidence): array
    {
        $this->window($evidence); $this->drained();
        $result = $this->comparison($evidence);
        $this->window($evidence); $this->drained(); return $result;
    }

    private function comparison(array $evidence): array
    {
        if (!is_array($evidence['before'] ?? null) || $evidence['before'] === []) {
            throw new \DomainException('Catalogue upgrade capture is missing.');
        }
        $compatible = true; $complete = true; $offers = 0; $issues = []; $mismatches = [];
        $unexpected = UpgradeComparison::read(DB::table('domains_tlds'))->where('catalogue_converted', true)->where(static function ($q): void {
            $q->whereNotNull('catalogue_facts')->orWhereNotNull('catalogue_published_revision')->orWhere('catalogue_revision', '!=', 1);
        })->count();
        if ($unexpected > 0) { $compatible = false; $mismatches['staging_changed'] = $unexpected; }

        Account::orderBy('server_id')->when(UpgradeComparison::locking(), fn($q) => $q->lockForUpdate())->chunk(250, static function ($accounts) use (&$compatible, &$complete, &$offers, &$issues, &$mismatches): void {
            foreach ($accounts as $account) {
                $report = (new UpgradeConversion)->stagingReport(CatalogueAcceptances::binding($account));
                $complete = $complete && $report['complete'];
                $compatible = $compatible && $report['staging_mismatches'] === [] && $report['source_unchanged'];
                $offers += $report['expected'];
                foreach ($report['issues'] as $code => $count) { $issues[$code] = ($issues[$code] ?? 0) + $count; }
                foreach ($report['staging_mismatches'] as $code => $count) { $mismatches[$code] = ($mismatches[$code] ?? 0) + $count; }
                if (!$report['source_unchanged']) { $mismatches['source_changed'] = ($mismatches['source_changed'] ?? 0) + 1; }
            }
        });
        $comparison = UpgradeComparison::compare($evidence['before']);
        return ['compatible' => $compatible && $complete && $comparison['compatible'], 'complete' => $complete,
            'changed' => $comparison['changed'], 'staging_mismatches' => $mismatches, 'issues' => $issues,
            'accounts' => $evidence['accounts']['count'], 'offers' => $offers, 'activated' => false,
            'activation_blocked' => 'caller_cutover_pending'];
    }
    /** Offline maintenance only. An unlocked check/receipt is never transition authority. */
    public function activate(array $evidence): array
    {
        self::outside();
        $actor = (int) Auth::id();
        if (PHP_SAPI !== 'cli' || $actor < 1) { throw new \DomainException('Authenticated maintenance CLI required.'); }
        $result = DB::transaction(function () use ($actor, $evidence): array {
            $user = User::whereKey($actor)->lockForUpdate()->firstOrFail();
            $user->setRelation('role', $user->role_id ? Role::whereKey($user->role_id)->lockForUpdate()->first() : null);
            foreach ([Staff::VIEW, Staff::POLICY, Staff::COSTS, StaffSettingsReview::permission('activate')] as $permission) {
                if (!$user->hasPermission($permission)) { throw new \DomainException('Current cutover staff permissions required.'); }
            }
            // Whole native prefix, then servers/accounts and gate, before ANY comparison snapshot.
            $native = ['users','credits','invoices','invoice_items','invoice_transactions','orders','services','categories','products','plans','prices','carts','cart_items'];
            foreach ($native as $table) { DB::table($table)->orderBy('id')->lockForUpdate()->chunk(250, static function (): void {}); }
            Server::where('extension','Domains')->orderBy('id')->lockForUpdate()->get();
            Account::orderBy('server_id')->lockForUpdate()->get();
            $rows = self::query()->lockForUpdate()->get(); $state = self::state($rows);
            if ($state['state'] !== 'paused' || ($evidence['generation'] ?? null) !== $state['generation']) {
                throw new \DomainException('Catalogue upgrade requires the same paused maintenance window.');
            }
            foreach (['domains_sales_policies','domains_tlds','domains_prices','settings',
                ...array_diff(UpgradeComparison::TABLES, [...$native,'domains_accounts','domains_sales_policies','domains_tlds','domains_prices','settings']),
                'domains_read_budgets'] as $table) {
                if (!Schema::hasTable($table)) { continue; }
                $columns = Schema::getColumnListing($table); $q = DB::table($table);
                foreach (in_array('id',$columns,true) ? ['id'] : ['server_id','account_fingerprint'] as $column) { $q->orderBy($column); }
                $q->lockForUpdate()->chunk(250, static function (): void {});
            }
            return UpgradeComparison::locked(function () use ($evidence, $state, $rows): array {
                if (($evidence['accounts'] ?? null) !== $this->accounts()) { throw new \DomainException('Catalogue account or credential set changed.'); }
                $this->drained(); $contract = CallerRouting::contract();
                try { $report = $this->comparison($evidence); }
                catch (\JsonException|\TypeError|\InvalidArgumentException) { throw new \DomainException('Malformed catalogue staging refuses activation.'); }
                if (!$report['compatible'] || !$report['complete']) { throw new \DomainException('Catalogue conversion/source/financial comparison failed.'); }
                $generation = $state['generation'] + 1;
                $digest = hash('sha256', OfferState::json([$evidence,$contract,$report]));
                $row = $rows->sole(); $row->value = OfferState::json(['state'=>'active','generation'=>$generation,'contract'=>$contract['id'],'digest'=>$digest]);
                $row->save(); StorefrontCatalogueCache::invalidateAfterCommit();
                return ['activated'=>true,'generation'=>$generation,'digest'=>$digest];
            });
        });
        // Bounded, idempotent wake is after the committed switch; a failed account is independent.
        CallerRouting::reset();
        foreach (Account::orderBy('server_id')->limit(8)->get() as $account) {
            try { OfferScheduler::routed()?->wake(CatalogueAcceptances::binding($account)); } catch (\Throwable) {}
        }
        return $result;
    }

}
