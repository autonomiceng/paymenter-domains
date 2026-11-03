<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\{Server, Setting};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances;

/** Explicit offline/maintenance seam, never called by installation or runtime bootstrap.
 * External maintenance must stop/drain old executables and financial writers before capture.
 * This first cut has no activation method: all callers and the worker must move together. */
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
        if (!is_array($state) || !in_array($state['state'] ?? null, ['legacy', 'paused'], true)
            || !is_int($state['generation'] ?? null) || $state['generation'] < 1) {
            throw new \DomainException('Catalogue upgrade state is invalid; keep maintenance active.');
        }
        return $state;
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
        Account::orderBy('server_id')->chunk(250, static function ($accounts) use ($hash, &$count): void {
            foreach ($accounts as $account) {
                $binding = CatalogueAcceptances::binding($account);
                hash_update($hash, json_encode([$binding->serverId, $binding->registrarKey, $binding->environment->value,
                    $binding->fingerprint], JSON_THROW_ON_ERROR)."\n"); $count++;
            }
        });
        return ['count' => $count, 'sha256' => hash_final($hash)];
    }

    /** Never clears a claim or assumes an expired lease proves a process has stopped. */
    private function drained(): void
    {
        if (DB::table('domains_observation_queues')->whereNotNull('catalogue_claim')->orWhereNotNull('offer_claim')->exists()
            || DB::table('domains_observation_work')->whereNotNull('claim')->exists()) {
            throw new \DomainException('Catalogue upgrade requires drained catalogue and observation claims.');
        }
        DB::table('domains_read_budgets')->orderBy('id')->chunk(250, static function ($rows): void {
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
        if (!is_array($evidence['before'] ?? null) || $evidence['before'] === []) {
            throw new \DomainException('Catalogue upgrade capture is missing.');
        }
        $compatible = true; $complete = true; $offers = 0; $issues = []; $mismatches = [];
        $unexpected = DB::table('domains_tlds')->where('catalogue_converted', true)->where(static function ($q): void {
            $q->whereNotNull('catalogue_facts')->orWhereNotNull('catalogue_published_revision')->orWhere('catalogue_revision', '!=', 1);
        })->count();
        if ($unexpected > 0) { $compatible = false; $mismatches['staging_changed'] = $unexpected; }

        Account::orderBy('server_id')->chunk(250, static function ($accounts) use (&$compatible, &$complete, &$offers, &$issues, &$mismatches): void {
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
        $this->window($evidence); $this->drained();
        return ['compatible' => $compatible && $complete && $comparison['compatible'], 'complete' => $complete,
            'changed' => $comparison['changed'], 'staging_mismatches' => $mismatches, 'issues' => $issues,
            'accounts' => $evidence['accounts']['count'], 'offers' => $offers, 'activated' => false,
            'activation_blocked' => 'caller_cutover_pending'];
    }
}
