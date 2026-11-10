<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

use App\Models\{Server, Setting, User, Role};
use Illuminate\Support\Facades\{DB, Auth, Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{FreshInstallation, OfferSchema, StaffSettingsReview, StorefrontCatalogueCache};
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Account;

/** Fresh initialization and active generation fencing. Retained conversion belongs to the pinned bridge. */
final class UpgradeGate
{
    public const KEY = 'domains_offer_upgrade';
    private static ?\WeakMap $retained = null;

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
        if (!is_array($state) || !in_array($state['state'] ?? null, ['legacy', 'paused', 'active', 'initializing'], true)
            || !is_int($state['generation'] ?? null) || $state['generation'] < 1) {
            throw new \DomainException('Catalogue upgrade state is invalid; keep maintenance active.');
        }
        if ($state['state'] === 'active' && (!is_string($state['contract'] ?? null) || !is_string($state['digest'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $state['contract']) || !preg_match('/^[a-f0-9]{64}$/D', $state['digest']))) { throw new \DomainException('Invalid active catalogue contract.'); }
        if ($state['state'] === 'initializing' && (!is_string($state['intent'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $state['intent'])
            || !is_string($state['manifest'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $state['manifest']))) { throw new \DomainException('Invalid Domains initialization intent.'); }
        return $state;
    }

    /** Internal lifecycle inspection grants no executable routing. */
    public static function initializationState(): array { return self::state(); }
    private static function executable(array $state): array
    {
        if ($state['state'] === 'initializing') { throw new \DomainException('Domains initialization is incomplete. Retry installation before processing.'); }
        if ($state['state'] === 'legacy' && $state['generation'] === 0) {
            // Cache only positive retained-install evidence, never mode or generation authority.
            self::$retained ??= new \WeakMap; $connection = DB::connection();
            if (!isset(self::$retained[$connection])) {
                if (!Schema::hasTable('domains_accounts') && FreshInstallation::uninstalled()) {
                    throw new \DomainException('Domains initialization is incomplete. Retry installation before processing.');
                }
                self::$retained[$connection] = true;
            }
        }
        return $state;
    }

    /** Advisory mode selection never takes the gate ahead of a caller's native/account prefix. */
    public static function routeState(): array { return self::executable(self::state(self::query()->get())); }
    /** Caller already owns its complete native/server/account prefix. */
    public static function currentRouteState(): array
    { if (DB::transactionLevel() === 0) { throw new \LogicException('Current routing requires the owned prefix.'); } return self::executable(self::state()); }
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



    /** Bounded digest of the entire binding set, including disabled accounts; no caller-chosen subset. */


    /** Never clears a claim or assumes an expired lease proves a process has stopped. */
    private static function drained(): void
    {
        if (DB::table('domains_observation_queues')->lockForUpdate()->whereNotNull('catalogue_claim')->orWhereNotNull('offer_claim')->exists()
            || DB::table('domains_observation_work')->lockForUpdate()->whereNotNull('claim')->exists()) {
            throw new \DomainException('Catalogue upgrade requires drained catalogue and observation claims.');
        }
        DB::table('domains_read_budgets')->lockForUpdate()->orderBy('id')->chunk(250, static function ($rows): void {
            foreach ($rows as $row) {
                // A waiting search marker is not a reservation.
                if (array_diff_key(json_decode($row->leases, true, 32, JSON_THROW_ON_ERROR), [\Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget::WAITING => true]) !== []) {
                    throw new \DomainException('Catalogue upgrade requires released read reservations.');
                }
            }
        });
    }

    public function capture(): array
    { throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE); }

    public function batch(AccountBinding $binding, array $evidence, int $limit = 100): array
    { throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE); }

    /** Report only. A compatible conversion is necessary, never sufficient, for activation.
     * Compare global obligations once, rather than once per account. */
    public function check(array $evidence): array
    { throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE); }


    private static function commitActive(Setting $row, array $state, array $evidence, array $contract, array $report): array
    {
        $generation = $state['generation'] + 1;
        $digest = hash('sha256', OfferState::json([$evidence,$contract,$report]));
        $row->value = OfferState::json(['state'=>'active','generation'=>$generation,'contract'=>$contract['id'],'digest'=>$digest]);
        $row->save(); StorefrontCatalogueCache::invalidateAfterCommit();
        return ['activated'=>true,'generation'=>$generation,'digest'=>$digest];
    }

    /** A privately constructed lifecycle capability cannot replace authenticated retained activation. */
    public static function initialize(FreshInstallation $intent): array
    {
        self::outside();
        return DB::transaction(static function () use ($intent): array {
            self::lockNative();
            Server::where('extension','Domains')->orderBy('id')->lockForUpdate()->get();
            Account::orderBy('server_id')->lockForUpdate()->get();
            $row = self::query()->lockForUpdate()->sole(); $state = $intent->check(true);
            self::drained(); $contract = CallerRouting::contract();
            return self::commitActive($row, $state, ['fresh_intent'=>$state['intent'],'manifest'=>$state['manifest']],
                $contract, ['compatible'=>true,'complete'=>true,'empty'=>true]);
        });
    }

    private static function lockNative(): array
    {
        $native = ['users','credits','invoices','invoice_items','invoice_transactions','orders','services','categories','products','plans','prices','carts','cart_items'];
        foreach ($native as $table) { DB::table($table)->orderBy('id')->lockForUpdate()->chunk(250, static function (): void {}); }
        return $native;
    }

    /** Offline maintenance only. An unlocked check/receipt is never transition authority. */
    public function activate(array $evidence): array
    { throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE); }

}
