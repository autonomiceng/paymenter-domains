<?php
// Cost refresh/publication adapted from Enom 444f31c Services/Catalogue.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueRate, PriceSnapshot, RenewalPrice};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, Tld};

final class PriceCatalogue
{
    public function __construct(private readonly PricingRules $rules) {}

    /** Cost-only legacy import: deliberately drops publication proof on touched rows. Reads finish before locking. Non-selling TLDs retain holder cost data; native-disabled servers are read-only.
     * Callback returns explicit rate/no-price entries; omissions NEVER withdraw existing terms.
     */
    public function sync(int $serverId, \Closure $read): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Provider reads cannot hold database locks.'); }
        $server = Server::findOrFail($serverId);
        if (!$server->enabled || $server->extension !== 'Domains' || !Account::where('server_id', $serverId)->exists()) {
            return ['synced' => 0, 'failed' => Tld::where('server_id', $serverId)->orderBy('id')->get()->map(static fn (Tld $tld): string => '.'.$tld->tld)->all()];
        }
        $summary = ['synced' => 0, 'failed' => []];
        foreach (Tld::where('server_id', $serverId)->orderBy('id')->get() as $tld) {
            try {
                $rates = $read($tld);
                if (!is_array($rates) || $rates === []) { throw new \DomainException('Explicit price evidence required.'); }
                $seen = [];
                foreach ($rates as $rate) {
                    if (!$rate instanceof CatalogueRate || !$rate->persistable || isset($seen[$rate->action.':'.$rate->term])) {
                        throw new \DomainException('Invalid or duplicate rate evidence.');
                    }
                    $seen[$rate->action.':'.$rate->term] = true;
                }
                $this->locked($tld, function (Tld $locked) use ($rates): void {
                    $rule = $this->rules->rule($locked, true); $hash = self::ruleHash($rule);
                    foreach ($rates as $rate) { $this->write($locked, $rate, $rule, $hash); }
                });
                $summary['synced']++;
            } catch (\Throwable) { $summary['failed'][] = '.'.$tld->tld; }
        }
        return $summary;
    }

    /** Shared batch import and existing native publication commit together, in native lock order. */
    public function import(\Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry $entry,
        \Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance $accepted, \Closure $recheck, \DateTimeImmutable $now, ?StaffSettingsReview $review = null, bool $keepStaffSettings = false): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Import owns its locks.'); }
        $accepted->binding->requireMatch($entry->binding);
        $tld = new Tld(['server_id' => $entry->binding->serverId, 'registrar_key' => $entry->binding->registrarKey,
            'account_fingerprint' => $entry->binding->fingerprint, 'tld' => $entry->tld]);
        app(NativeCatalogue::class)->refresh($tld, $now, function (Tld $locked, Server $server, Account $account) use ($entry, $accepted, $recheck, $now, $review, $keepStaffSettings): void {
            $prior = ($review || $keepStaffSettings) && !$locked->wasRecentlyCreated ? [$locked->register_enabled, $locked->transfer_enabled, $locked->renew_enabled] : null;
            $recheck($server, $account); $row = $accepted->endings[$entry->tld];
            $rates = array_filter($entry->rates, static fn (CatalogueRate $rate): bool => $rate->action !== 'register'
                || ($rate->term >= $locked->min_years && $rate->term <= $locked->max_years));
            $terms = array_values(array_intersect($entry->terms, $row['register']));
            $terms = array_values(array_filter($terms, static fn (int $term): bool => $term >= $locked->min_years && $term <= $locked->max_years));
            $renewal = $row['renewal_safe'] && $entry->renewalSupported && in_array(1, $row['renew'], true);
            $registry = $row['registry'] && $entry->registrySupported;
            $proof = ['catalogue_revision' => $accepted->revision, 'accepted_terms' => $terms, 'registry' => $registry, 'renewal_safe' => $renewal];
            $rule = $hash = null;
            foreach ($rates as $rate) {
                if (!in_array($rate->term, $row[$rate->action], true)) { throw new \DomainException('Unaccepted action term.'); }
                $rule ??= $this->rules->rule($locked, true); $hash ??= self::ruleHash($rule);
                $this->write($locked, $rate, $rule, $hash, $proof);
            }
            // Initialize new TLDs only; acceptance/provider changes must not erase saved bounds.
            if ($locked->wasRecentlyCreated && $terms) { $locked->min_years = min($terms); $locked->max_years = max($terms); }
            $locked->fill(['register_enabled' => false, 'transfer_enabled' => false, 'renew_enabled' => $renewal,
                'registry_fields' => $entry->registryFields])->save();
            $eligible = function (string $action, int $term) use ($locked, $now): bool {
                try { $this->current((int) $locked->server_id, $locked->account_fingerprint, $locked->tld, $action, $term, 'USD', $now, true, $locked); return true; }
                catch (\DomainException) { return false; }
            };
            $annual = $renewal && $eligible('renew', 1);
            $locked->register_enabled = false;
            if ($registry && $annual) {
                foreach ($terms as $term) {
                    if ($eligible('register', $term)) { $locked->register_enabled = true; break; }
                }
            }
            $locked->transfer_enabled = $registry && $annual && in_array(1, $row['transfer'], true) && $eligible('transfer', 1);
            if ($prior !== null) { $locked->register_enabled = $locked->register_enabled && $prior[0]; $locked->transfer_enabled = $locked->transfer_enabled && $prior[1]; $locked->renew_enabled = $locked->renew_enabled && $prior[2]; }
            $locked->save();
        }, $review);
    }

    public function republish(Tld $tld): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Republication owns its lock order.'); }
        $this->locked($tld, function (Tld $locked): void {
            $this->republishLocked($locked);
        });
    }

    /** Caller owns native publication prefix, server/account, policy and TLD locks. */
    public function republishLocked(Tld $locked): void
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Locked republication requires the outer writer.'); }
        $rule = $hash = null;
        foreach (Price::where('server_id', $locked->server_id)->where('tld', $locked->tld)->orderBy('id')->lockForUpdate()->get() as $price) {
            if ($price->eligibility['price_unavailable'] ?? false) { continue; }
            if ($price->action === 'register' && ($price->term < $locked->min_years || $price->term > $locked->max_years)) { continue; }
            $total = $price->wholesale === null ? null : self::cost($price->wholesale);
            $units = $total === null ? null : PricingRule::units($total);
            if ($units !== null && $units % $price->term !== 0) { throw new \DomainException('Unsupported per-year precision.'); }
            $rate = new CatalogueRate($price->action, $price->term, $units === null ? null : PricingRule::decimal(intdiv($units, $price->term), 4),
                $price->source, $price->observed_at->toDateTimeImmutable(), $price->fresh_until?->toDateTimeImmutable(), $price->currency);
            $rule ??= $this->rules->rule($locked, true); $hash ??= self::ruleHash($rule);
            $this->write($locked, $rate, $rule, $hash, array_intersect_key($price->eligibility, array_flip(['catalogue_revision', 'accepted_terms', 'registry', 'renewal_safe'])));
        }
    }

    /** Quarantine an explicit malformed row, preserving holder retail and price identities. */
    public function unavailable(\Paymenter\Extensions\Servers\Domains\Data\CatalogueUnavailable $entry,
        \Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance $accepted, \Closure $recheck, \DateTimeImmutable $now): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Quarantine owns its locks.'); }
        $accepted->binding->requireMatch($entry->binding);
        if (!isset($accepted->endings[$entry->tld]) || $entry->observedAt > $now) { throw new \DomainException('Unaccepted quarantine.'); }
        $tld = new Tld(['server_id' => $entry->binding->serverId, 'registrar_key' => $entry->binding->registrarKey,
            'account_fingerprint' => $entry->binding->fingerprint, 'tld' => $entry->tld]);
        $this->locked($tld, function (Tld $locked, Server $server, Account $account) use ($entry, $accepted, $recheck): void {
            $recheck($server, $account);
            $rows = Price::where('server_id', $server->id)->where('tld', $entry->tld)->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $price) {
                if ($price->observed_at > $entry->observedAt) { throw new \DomainException('Stale quarantine.'); }
            }
            // Even a never-priced ending gets nullable current rows for staff inspection.
            foreach (['register', 'renew', 'transfer'] as $action) {
                foreach ($accepted->endings[$entry->tld][$action] as $term) {
                    if (!$rows->contains(fn (Price $price): bool => $price->action === $action && $price->term === $term && $price->currency === 'USD')) {
                        $rows->push(new Price(['server_id' => $server->id, 'registrar_key' => $entry->binding->registrarKey,
                            'account_fingerprint' => $entry->binding->fingerprint, 'tld' => $entry->tld,
                            'action' => $action, 'term' => $term, 'currency' => 'USD', 'revision' => 0]));
                    }
                }
            }
            foreach ($rows as $price) {
                $price->fill(['wholesale' => null, 'retail' => $price->action === 'renew' ? $price->retail : null,
                    'unit' => 'term_total', 'source' => 'namesilo_account_annual', 'observed_at' => $entry->observedAt,
                    'fresh_until' => null, 'revision' => $price->revision + 1, 'hold_reason' => 'unknown_price',
                    'eligibility' => ['staff_alert' => 'unknown_price', 'price_unavailable' => true,
                        'staff_label' => 'Price unavailable']])->save();
            }
            $locked->fill(['register_enabled' => false, 'transfer_enabled' => false])->save();
        });
    }

    /** Published annual retail survives unknown/stale costs and rule republication failures.
     * Slice 24 projects this into native service.price under locks; issued invoices remain frozen.
     */
    public function renewal(int $serverId, string $fingerprint, string $tld, string $currency, \DateTimeImmutable $now, bool $lock = false, ?Tld $locked = null): RenewalPrice
    {
        if ($lock && (DB::transactionLevel() !== 1 || !$locked || (int) $locked->server_id !== $serverId
            || $locked->account_fingerprint !== $fingerprint || $locked->tld !== $tld)) { throw new \LogicException('Locked holding TLD required.'); }
        $query = Price::where(['server_id' => $serverId, 'account_fingerprint' => $fingerprint, 'tld' => $tld,
            'action' => 'renew', 'term' => 1, 'currency' => $currency]);
        if ($lock) { $query->lockForUpdate(); }
        $price = $query->first();
        if (!$price || $price->retail === null || $currency !== 'USD' || $price->unit !== 'term_total') { throw new \DomainException('No published annual retail.'); }
        $retail = self::retail($price->retail);
        if (PricingRule::minor($retail) <= 0) { throw new \DomainException('Positive published retail required; complimentary authority is separate.'); }
        try {
            $current = $this->current($serverId, $fingerprint, $tld, 'renew', 1, $currency, $now, $lock, $locked);
            return new RenewalPrice($current->id, $current->revision, $current->retail, $currency, false, $current->staffAlert);
        } catch (\DomainException|\InvalidArgumentException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return new RenewalPrice($price->id, $price->revision, $retail, $currency, true, $price->wholesale === null ? 'unknown_price' : 'stale');
        }
    }

    /** Fresh cost evidence only; does not check seller, ownership, funding or driver eligibility.
     * lock=true requires caller-held server, account, policy and TLD locks in storage.md order.
     */
    public function current(int $serverId, string $fingerprint, string $tld, string $action, int $term,
        string $currency, \DateTimeImmutable $now, bool $lock = false, ?Tld $locked = null): PriceSnapshot
    {
        if ($lock && DB::transactionLevel() === 0) { throw new \LogicException('Price locks require an outer transaction.'); }
        if ($lock && (!$locked || (int) $locked->server_id !== $serverId || $locked->tld !== $tld || $locked->account_fingerprint !== $fingerprint)) {
            throw new \LogicException('Locked price validation requires its caller-held TLD.');
        }
        $tldRow = $locked ?? Tld::where(['server_id' => $serverId, 'tld' => $tld, 'account_fingerprint' => $fingerprint])->firstOrFail();
        $hash = self::ruleHash($this->rules->rule($tldRow, $lock));
        $query = Price::where(['server_id' => $serverId, 'account_fingerprint' => $fingerprint, 'tld' => $tld,
            'action' => $action, 'term' => $term, 'currency' => $currency]);
        if ($lock) { $query->lockForUpdate(); }
        $price = $query->first();
        return self::currentEvidence($price, $hash, $now);
    }

    /** Pure saved-price validation for render snapshots; callers qualify the row's binding and action.
     * A synced cost has no time limit: sales keep the last synced cost until the next sync. */
    public static function currentEvidence(?Price $price, ?string $ruleHash, \DateTimeImmutable $now): PriceSnapshot
    {
        if (!$price || $price->wholesale === null || $price->retail === null || $price->hold_reason !== null
            || $price->unit !== 'term_total' || !$price->fresh_until || $price->observed_at > $now) {
            throw new \DomainException('No current eligible price.');
        }
        if ($ruleHash === null || ($price->eligibility['rule_hash'] ?? null) !== $ruleHash) { throw new \DomainException('Price rule changed; republication required.'); }
        return new PriceSnapshot($price->id, $price->revision, $price->server_id, $price->registrar_key, $price->account_fingerprint, $price->tld, $price->action, $price->term,
            $price->currency, self::cost($price->wholesale), self::retail($price->retail), $price->source,
            $price->observed_at->toDateTimeImmutable(), $price->fresh_until->toDateTimeImmutable(), $price->eligibility['staff_alert'] ?? null);
    }

    private function locked(Tld $tld, \Closure $write): void
    {
        DB::transaction(function () use ($tld, $write): void {
            $server = Server::whereKey($tld->server_id)->lockForUpdate()->firstOrFail();
            $account = Account::where('server_id', $server->id)->lockForUpdate()->firstOrFail();
            if (!$server->enabled || $server->extension !== 'Domains' || $account->account_fingerprint !== $tld->account_fingerprint) {
                throw new \DomainException('Catalogue binding mismatch.');
            }
            $locked = Tld::where(['server_id' => $tld->server_id, 'tld' => $tld->tld])->lockForUpdate()->first();
            if (!$locked && !$tld->exists) { $locked = $tld; $locked->save(); $locked->refresh(); }
            if (!$locked) { throw new \DomainException('Catalogue ending disappeared.'); }
            if ($locked->account_fingerprint !== $account->account_fingerprint || $locked->registrar_key !== $account->registrar_key) {
                throw new \DomainException('Catalogue account mismatch.');
            }
            $write($locked, $server, $account);
        });
    }

    private function write(Tld $tld, CatalogueRate $rate, array $rule, string $hash, array $publication = []): void
    {
        if ($rate->action === 'register' && ($rate->term < $tld->min_years || $rate->term > $tld->max_years)) {
            throw new \DomainException('Unsupported registration term.');
        }
        $price = Price::where(['server_id' => $tld->server_id, 'tld' => $tld->tld, 'action' => $rate->action,
            'term' => $rate->term, 'currency' => $rate->currency])->lockForUpdate()->first();
        if ($price && $price->observed_at > $rate->observedAt) { throw new \DomainException('Stale provider read.'); }
        $wholesale = $rate->perYear === null ? null : PricingRule::decimal(PricingRule::units($rate->perYear) * $rate->term, 4);
        [$retail, $margin] = self::quote($rate->action, $rate->term, $rate->perYear, $rule, $price?->retail);
        if ($wholesale !== null) { self::cost($wholesale); }
        $price ??= new Price(['server_id' => $tld->server_id, 'registrar_key' => $tld->registrar_key, 'account_fingerprint' => $tld->account_fingerprint,
            'tld' => $tld->tld, 'action' => $rate->action, 'term' => $rate->term, 'currency' => $rate->currency, 'revision' => 0]);
        $price->fill(['wholesale' => $wholesale, 'retail' => $retail, 'unit' => 'term_total', 'source' => $rate->source,
            'observed_at' => $rate->observedAt, 'fresh_until' => $rate->freshUntil, 'revision' => $price->revision + 1,
            'eligibility' => [...$publication, 'source_unit' => $rate->action === 'register' ? 'exact_term_annual_rate' : 'annual', 'staff_alert' => $margin->staffAlert, 'rule_hash' => $hash],
            'hold_reason' => $margin->hold])->save();
    }

    /** Term retail for one rule and its margin decision. Publication and the staff preview share it.
     * Renewals that cannot be published (unknown or nonpositive price) keep the prior retail; below-cost renewals are
     * still billed; below-cost registrations and transfers are held. */
    public static function quote(string $action, int $term, ?string $perYear, array $rule, ?string $prior): array
    {
        $annualRetail = $perYear === null ? null : PricingRule::price($perYear, $rule, $action);
        $retail = $annualRetail === null ? null : PricingRule::total($annualRetail, $term);
        $margin = MarginPolicy::assess($action, $perYear, $annualRetail);
        if ($action === 'renew' && !$margin->publishable) { $retail = $prior; }
        return [$retail, $margin];
    }

    /** Saved rows a staff price change republishes, in register, renew, transfer order. */
    public static function sold(Tld $tld, ?\Illuminate\Support\Collection $preloaded = null): \Illuminate\Support\Collection
    {
        $order = ['register' => 0, 'renew' => 1, 'transfer' => 2];
        return ($preloaded ?? Price::where('server_id', $tld->server_id)->where('tld', $tld->tld)->where('currency', 'USD')->orderBy('id')->get())
            ->reject(fn (Price $price): bool => ($price->eligibility['price_unavailable'] ?? false)
                || ($price->action === 'register' && ($price->term < $tld->min_years || $price->term > $tld->max_years)))
            ->sortBy(fn (Price $price): int => $order[$price->action] * 100 + $price->term)->values();
    }

    /** A saved price is on an older rule, and every cost needed to redo it is here. */
    public function behindRule(Tld $tld, \DateTimeImmutable $now): bool
    {
        $hash = self::ruleHash($this->rules->rule($tld));
        if (!self::sold($tld)->contains(fn (Price $price): bool => ($price->eligibility['rule_hash'] ?? null) !== $hash)) { return false; }
        try { self::requireCosts($tld, $now); return true; } catch (\DomainException) { return false; }
    }

    /** What saving a rule would publish: cost, current and new term retail, without writing. */
    public static function preview(Tld $tld, array $rule, \DateTimeImmutable $now): array
    {
        $rule = PricingRule::validate($rule);
        return self::sold($tld)->map(function (Price $price) use ($rule, $now): array {
            $cost = $price->wholesale === null ? null : self::cost($price->wholesale);
            $units = $cost === null ? null : PricingRule::units($cost);
            $perYear = $units === null || $units % $price->term !== 0 ? null : PricingRule::decimal(intdiv($units, $price->term), 4);
            $current = $price->retail === null ? null : self::retail($price->retail);
            [$retail, $margin] = self::quote($price->action, $price->term, $perYear, $rule, $current);
            return ['action' => $price->action, 'term' => $price->term, 'cost' => $cost, 'current' => $current,
                'new' => $retail === null ? null : self::retail($retail), 'hold' => $margin->hold, 'alert' => $margin->staffAlert,
                'synced' => $price->fresh_until !== null && $price->observed_at <= $now];
        })->all();
    }

    /** Staff saves publish at once, so every saved term needs a synced read and the one-year renewal needs a cost.
     * A read without a cost means the registrar does not offer that term. Old reads still count. */
    public static function requireCosts(Tld $tld, \DateTimeImmutable $now, ?\Illuminate\Support\Collection $preloaded = null): void
    {
        $rows = self::sold($tld, $preloaded);
        if (!$rows->contains(fn (Price $price): bool => $price->action === 'renew' && $price->term === 1 && $price->wholesale !== null)
            || $rows->contains(fn (Price $price): bool => $price->fresh_until === null || $price->observed_at > $now)) {
            throw new \DomainException('Costs required before publication.');
        }
    }

    private static function ruleHash(array $rule): string
    {
        return hash('sha256', json_encode($rule, JSON_THROW_ON_ERROR));
    }

    public static function cost(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        return PricingRule::decimal(PricingRule::units($value), 4);
    }

    public static function retail(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        return PricingRule::decimal(PricingRule::units($value, 2));
    }
}
