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

    /** Reads finish before locking. Non-selling TLDs retain holder cost data; native-disabled servers are read-only.
     * Callback returns explicit rate/no-price entries; omissions NEVER withdraw existing terms.
     */
    public function sync(int $serverId, \Closure $read): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Provider reads cannot hold database locks.'); }
        $summary = ['synced' => 0, 'failed' => []];
        foreach (Tld::where('server_id', $serverId)->orderBy('id')->get() as $tld) {
            try {
                $rates = $read($tld);
                if (!is_array($rates) || $rates === []) { throw new \DomainException('Explicit price evidence required.'); }
                $seen = [];
                foreach ($rates as $rate) {
                    if (!$rate instanceof CatalogueRate || isset($seen[$rate->action.':'.$rate->term])) {
                        throw new \DomainException('Invalid or duplicate rate evidence.');
                    }
                    $seen[$rate->action.':'.$rate->term] = true;
                }
                $this->locked($tld, function (Tld $locked) use ($rates): void {
                    foreach ($rates as $rate) { $this->write($locked, $rate); }
                });
                $summary['synced']++;
            } catch (\Throwable) { $summary['failed'][] = '.'.$tld->tld; }
        }
        return $summary;
    }

    public function republish(Tld $tld): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Republication owns its lock order.'); }
        $this->locked($tld, function (Tld $locked): void {
            foreach (Price::where('server_id', $locked->server_id)->where('tld', $locked->tld)->orderBy('id')->lockForUpdate()->get() as $price) {
                $total = $price->wholesale === null ? null : self::cost($price->wholesale);
                $units = $total === null ? null : PricingRule::units($total);
                if ($units !== null && $units % $price->term !== 0) { throw new \DomainException('Unsupported per-year precision.'); }
                $rate = new CatalogueRate($price->action, $price->term, $units === null ? null : PricingRule::decimal(intdiv($units, $price->term), 4),
                    $price->source, $price->observed_at->toDateTimeImmutable(), $price->fresh_until?->toDateTimeImmutable(), $price->currency);
                $this->write($locked, $rate);
            }
        });
    }

    /** Published annual retail survives unknown/stale costs and rule republication failures.
     * Slice 24 projects this into native service.price under locks; issued invoices remain frozen.
     */
    public function renewal(int $serverId, string $fingerprint, string $tld, string $currency, \DateTimeImmutable $now): RenewalPrice
    {
        $price = Price::where(['server_id' => $serverId, 'account_fingerprint' => $fingerprint, 'tld' => $tld,
            'action' => 'renew', 'term' => 1, 'currency' => $currency])->first();
        if (!$price || $price->retail === null || $currency !== 'USD' || $price->unit !== 'term_total') { throw new \DomainException('No published annual retail.'); }
        $retail = self::retail($price->retail);
        if (PricingRule::minor($retail) <= 0) { throw new \DomainException('Positive published retail required; complimentary authority is separate.'); }
        try {
            $current = $this->current($serverId, $fingerprint, $tld, 'renew', 1, $currency, $now);
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
        $hash = $this->ruleHash($tldRow, $lock);
        $query = Price::where(['server_id' => $serverId, 'account_fingerprint' => $fingerprint, 'tld' => $tld,
            'action' => $action, 'term' => $term, 'currency' => $currency]);
        if ($lock) { $query->lockForUpdate(); }
        $price = $query->first();
        if (!$price || $price->wholesale === null || $price->retail === null || $price->hold_reason !== null
            || $price->unit !== 'term_total' || !$price->fresh_until || $price->observed_at > $now || $price->fresh_until <= $now) {
            throw new \DomainException('No current eligible price.');
        }
        if (($price->eligibility['rule_hash'] ?? null) !== $hash) { throw new \DomainException('Price rule changed; republication required.'); }
        return new PriceSnapshot($price->id, $price->revision, $serverId, $price->registrar_key, $fingerprint, $tld, $action, $term,
            $currency, self::cost($price->wholesale), self::retail($price->retail), $price->source,
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
            $locked = Tld::whereKey($tld->id)->lockForUpdate()->firstOrFail();
            if ($locked->account_fingerprint !== $account->account_fingerprint || $locked->registrar_key !== $account->registrar_key) {
                throw new \DomainException('Catalogue account mismatch.');
            }
            $write($locked);
        });
    }

    private function write(Tld $tld, CatalogueRate $rate): void
    {
        if ($rate->action === 'register' && ($rate->term < $tld->min_years || $rate->term > $tld->max_years)) {
            throw new \DomainException('Unsupported registration term.');
        }
        $price = Price::where(['server_id' => $tld->server_id, 'tld' => $tld->tld, 'action' => $rate->action,
            'term' => $rate->term, 'currency' => $rate->currency])->lockForUpdate()->first();
        if ($price && $price->observed_at > $rate->observedAt) { throw new \DomainException('Stale provider read.'); }
        $wholesale = $rate->perYear === null ? null : PricingRule::decimal(PricingRule::units($rate->perYear) * $rate->term, 4);
        $annualRetail = $rate->perYear === null ? null : PricingRule::price($rate->perYear, $this->rules->rule($tld), $rate->action);
        $retail = $annualRetail === null ? null : PricingRule::total($annualRetail, $rate->term);
        $margin = MarginPolicy::assess($rate->action, $rate->perYear, $annualRetail);
        if ($wholesale !== null) { self::cost($wholesale); }
        if ($rate->action === 'renew' && !$margin->publishable) { $retail = $price?->retail; }
        $price ??= new Price(['server_id' => $tld->server_id, 'registrar_key' => $tld->registrar_key, 'account_fingerprint' => $tld->account_fingerprint,
            'tld' => $tld->tld, 'action' => $rate->action, 'term' => $rate->term, 'currency' => $rate->currency, 'revision' => 0]);
        $price->fill(['wholesale' => $wholesale, 'retail' => $retail, 'unit' => 'term_total', 'source' => $rate->source,
            'observed_at' => $rate->observedAt, 'fresh_until' => $rate->freshUntil, 'revision' => $price->revision + 1,
            'eligibility' => ['source_unit' => $rate->action === 'register' ? 'exact_term_annual_rate' : 'annual', 'staff_alert' => $margin->staffAlert, 'rule_hash' => $this->ruleHash($tld)],
            'hold_reason' => $margin->hold])->save();
    }

    private function ruleHash(Tld $tld, bool $lock = false): string
    {
        return hash('sha256', json_encode($this->rules->rule($tld, $lock), JSON_THROW_ON_ERROR));
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
