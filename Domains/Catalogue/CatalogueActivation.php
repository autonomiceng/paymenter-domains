<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use App\Models\{Server, Setting};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Installation\Installer;
use Paymenter\Extensions\Servers\Domains\Services\StorefrontCatalogueCache;

final class CatalogueActivation
{
    public const KEY = 'domains_catalogue_activation';
    public const INVALID_STATE = 'Catalogue activation state is invalid; keep maintenance active.';

    private static function query(): \Illuminate\Database\Eloquent\Builder
    { return Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key', self::KEY); }

    private static function state(?\Illuminate\Support\Collection $rows = null): array
    {
        // A transaction may already own an older consistent snapshot. Only a locking read is current.
        $rows ??= DB::transactionLevel() > 0 ? self::query()->lockForUpdate()->get() : self::query()->get();
        if ($rows->isEmpty()) { return ['state' => 'absent', 'generation' => 0]; }
        if ($rows->count() !== 1 || !$rows[0]->encrypted || $rows[0]->type !== 'string') {
            throw new \DomainException(self::INVALID_STATE);
        }
        try { $state = json_decode($rows[0]->value, true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \DomainException(self::INVALID_STATE); }
        if (!is_array($state) || !in_array($state['state'] ?? null, ['active', 'initializing'], true)
            || !is_int($state['generation'] ?? null) || $state['generation'] < 1) {
            throw new \DomainException(self::INVALID_STATE);
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
        if ($state['state'] !== 'active' || !hash_equals(Installer::CONTRACT, $state['contract'])) {
            throw new \DomainException(Installer::MESSAGE);
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
        $s = self::executable(self::state());
        if ($s['generation'] !== $generation) { throw new \DomainException('Catalogue active generation changed.'); }
    }
    public static function generation(): int { return self::state()['generation']; }

    private static function outside(): void
    { if (DB::transactionLevel() !== 0) { throw new \LogicException('Catalogue activation checks must run outside locks.'); } }

    /** Never clears a claim or assumes an expired lease proves a process has stopped. */
    private static function drained(): void
    {
        if (DB::table('domains_observation_queues')->lockForUpdate()->whereNotNull('catalogue_claim')->orWhereNotNull('offer_claim')->exists()
            || DB::table('domains_observation_work')->lockForUpdate()->whereNotNull('claim')->exists()) {
            throw new \DomainException('Catalogue activation requires drained catalogue and observation claims.');
        }
        DB::table('domains_read_budgets')->lockForUpdate()->orderBy('id')->chunk(250, static function ($rows): void {
            foreach ($rows as $row) {
                // A waiting search marker is not a reservation.
                if (array_diff_key(json_decode($row->leases, true, 32, JSON_THROW_ON_ERROR), [\Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget::WAITING => true]) !== []) {
                    throw new \DomainException('Catalogue activation requires released read reservations.');
                }
            }
        });
    }

    private static function commitActive(Setting $row, array $state, array $evidence, array $contract, array $report): array
    {
        $generation = $state['generation'] + 1;
        $digest = hash('sha256', OfferState::json([$evidence,$contract,$report]));
        $row->value = OfferState::json(['state'=>'active','generation'=>$generation,'contract'=>$contract['id'],'digest'=>$digest]);
        $row->save(); StorefrontCatalogueCache::invalidateAfterCommit();
        return ['activated'=>true,'generation'=>$generation,'digest'=>$digest];
    }

    /** Only the invocation owning the durable install intent can activate the catalogue. */
    public static function initialize(Installer $intent): array
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

    private static function lockNative(): void
    {
        $native = ['users','credits','invoices','invoice_items','invoice_transactions','orders','services','categories','products','plans','prices','carts','cart_items'];
        foreach ($native as $table) { DB::table($table)->orderBy('id')->lockForUpdate()->chunk(250, static function (): void {}); }
    }
}
