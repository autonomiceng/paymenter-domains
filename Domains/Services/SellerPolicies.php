<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, SaleSelection};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, SalesPolicy, Tld};

final class SellerPolicies
{
    public function __construct(private readonly PriceCatalogue $prices, private readonly AccountBindings $bindings) {}

    public function initialize(string $tld): SalesPolicy
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Initialization owns its policy lock order.'); }
        CanonicalName::parse('example.'.$tld, [$tld]);
        return DB::transaction(static function () use ($tld): SalesPolicy {
            $existing = SalesPolicy::where('tld', $tld)->lockForUpdate()->first();
            // A concurrent first insert must abort on the unique key, never catch-and-continue.
            return $existing ?? SalesPolicy::withPolicyWrite(static fn () => SalesPolicy::create(['tld' => $tld, 'revision' => 1]));
        });
    }

    /** No provider reads under locks; processing/configuration guard is local. Switch affects future orders only. */
    public function change(string $tld, int $expectedRevision, ?int $serverId, bool $register, bool $transfer,
        \DateTimeImmutable $now, ?StaffSettingsReview $review = null): SalesPolicy
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Seller change owns its lock order.'); }
        return DB::transaction(function () use ($tld, $expectedRevision, $serverId, $register, $transfer, $now, $review): SalesPolicy {
            $review?->lockActor(); $review?->lockAccounts(); $review?->check();
            if ($review && ($review->action !== 'seller' || Tld::findOrFail($review->tldId)->tld !== $tld)) { throw new \DomainException('Seller review mismatch.'); }
            $account = $serverId === null ? null : $this->account($serverId, true);
            $policy = SalesPolicy::where('tld', $tld)->lockForUpdate()->firstOrFail();
            if ($policy->revision !== $expectedRevision) { throw new \DomainException('Stale seller policy.'); }
            if (!$account && ($register || $transfer)) { throw new \DomainException('Enabled sale requires a seller.'); }
            if ($account) {
                $catalogue = $this->tld($account, $tld, true);
                if (!$catalogue->enabled || !$catalogue->renew_enabled) { throw new \DomainException('Seller catalogue is unavailable.'); }
                $this->prices->current($serverId, $account->account_fingerprint, $tld, 'renew', 1, 'USD', $now, true, $catalogue);
                foreach (['register' => $register, 'transfer' => $transfer] as $action => $enabled) {
                    if (!$enabled) { continue; }
                    if (!$catalogue->{$action.'_enabled'}) { throw new \DomainException('Seller action is unsupported.'); }
                    $terms = $action === 'transfer' ? [1] : array_filter(range(1, 10), static fn (int $term): bool => $term >= $catalogue->min_years && $term <= $catalogue->max_years);
                    $available = false;
                    foreach ($terms as $term) {
                        try { PublicationEligibility::require($catalogue, $action, $term, true); $this->prices->current($serverId, $account->account_fingerprint, $tld, $action, $term, 'USD', $now, true, $catalogue); $available = true; }
                        catch (\DomainException) { /* Another supported registration term may still be available. */ }
                    }
                    if (!$available) { throw new \DomainException('Seller has no eligible action price.'); }
                }
            }
            $policy->fill(['server_id' => $serverId, 'registrar_key' => $account?->registrar_key, 'account_fingerprint' => $account?->account_fingerprint,
                'register_enabled' => $register, 'transfer_enabled' => $transfer, 'revision' => $policy->revision + 1]);
            SalesPolicy::withPolicyWrite(static fn () => $policy->save());
            $review?->record();
            return $policy;
        });
    }

    /** Unlocked display lookup only. Every line is rechecked in admit. */
    public function selection(string $tld, string $action, int $term, \DateTimeImmutable $now): SaleSelection
    {
        $policy = SalesPolicy::where('tld', $tld)->firstOrFail();
        $this->requireAction($policy, $action, $term);
        $account = $this->account($policy->server_id, false);
        $catalogue = $this->tld($account, $tld, false);
        $this->requireTld($catalogue, $action, $term);
        PublicationEligibility::require($catalogue, $action, $term);
        $this->prices->current($policy->server_id, $policy->account_fingerprint, $tld, 'renew', 1, 'USD', $now);
        $price = $this->prices->current($policy->server_id, $policy->account_fingerprint, $tld, $action, $term, 'USD', $now);
        return new SaleSelection($tld, $policy->server_id, $policy->registrar_key, $policy->account_fingerprint,
            $policy->revision, $action, $term, 'USD', $price->id, $price->revision);
    }

    /** Fresh display inputs only; not an admitted selection or a cached checkout authority. */
    public function displayCatalogue(string $ending): array
    {
        $policy = SalesPolicy::where('tld', $ending)->firstOrFail();
        if (!$policy->server_id || (!$policy->register_enabled && !$policy->transfer_enabled)) { throw new \DomainException('This acquisition is unavailable.'); }
        $account = $this->account($policy->server_id, false);
        $catalogue = $this->tld($account, $ending, false);
        if ($policy->account_fingerprint !== $account->account_fingerprint || $policy->registrar_key !== $account->registrar_key) {
            throw new \DomainException('Seller selection changed; refresh checkout.');
        }
        return [$policy, $account, $catalogue];
    }

    /** Join native checkout AFTER user/coupon/product locks. No nested transaction or per-line commit.
     * Caller binds ALL invoice/service/reservation/operation writes before its outer commit.
     * @param non-empty-list<SaleSelection> $lines @return list<\Paymenter\Extensions\Servers\Domains\Data\PriceSnapshot> input order
     */
    public function admit(array $lines, \DateTimeImmutable $now): array
    {
        if (DB::transactionLevel() === 0) { throw new \LogicException('Admission joins the native checkout transaction.'); }
        if ($lines === [] || !array_is_list($lines)) { throw new \DomainException('Domain cart lines required.'); }
        foreach ($lines as $line) { if (!$line instanceof SaleSelection) { throw new \DomainException('Invalid domain selection.'); } }
        $ids = array_values(array_unique(array_map(static fn (SaleSelection $s): int => $s->serverId, $lines))); sort($ids, SORT_NUMERIC);
        $servers = $accounts = [];
        foreach ($ids as $id) { $servers[$id] = Server::whereKey($id)->lockForUpdate()->firstOrFail(); }
        foreach ($ids as $id) { $accounts[$id] = Account::where('server_id', $id)->lockForUpdate()->firstOrFail(); }
        foreach ($ids as $id) { $this->bindings->requireProcessingLocked($servers[$id], $accounts[$id]); }
        $names = array_values(array_unique(array_map(static fn (SaleSelection $s): string => $s->tld, $lines))); sort($names, SORT_STRING);
        $policies = [];
        foreach ($names as $name) { $policies[$name] = SalesPolicy::where('tld', $name)->lockForUpdate()->firstOrFail(); }
        foreach ($lines as $selected) {
            $policy = $policies[$selected->tld]; $account = $accounts[$selected->serverId];
            $this->requireAction($policy, $selected->action, $selected->term);
            if ($policy->revision !== $selected->policyRevision || $policy->server_id !== $selected->serverId
                || $policy->registrar_key !== $selected->registrar || $policy->account_fingerprint !== $selected->fingerprint
                || $account->account_fingerprint !== $selected->fingerprint || $selected->currency !== 'USD') {
                throw new \DomainException('Seller selection changed; refresh checkout.');
            }
        }
        $catalogues = Tld::where(function ($query) use ($lines): void {
            foreach ($lines as $s) {
                $query->orWhere(static fn ($tuple) => $tuple->where('server_id', $s->serverId)
                    ->where('tld', $s->tld)->where('account_fingerprint', $s->fingerprint));
            }
        })->orderBy('id')->lockForUpdate()->get()->keyBy(static fn (Tld $t): string => $t->server_id.':'.$t->tld);
        // Lock the entire batch before reentrant per-line price validation, regardless of cart order.
        Price::where(function ($query) use ($lines): void {
            foreach ($lines as $s) {
                $query->orWhere(static fn ($tuple) => $tuple->where('server_id', $s->serverId)->where('tld', $s->tld)
                    ->where('account_fingerprint', $s->fingerprint)->where('currency', $s->currency)->where('action', 'renew')->where('term', 1));
                $query->orWhere(static fn ($tuple) => $tuple->where('server_id', $s->serverId)->where('tld', $s->tld)
                    ->where('account_fingerprint', $s->fingerprint)->where('currency', $s->currency)->where('action', $s->action)->where('term', $s->term));
            }
        })->orderBy('id')->lockForUpdate()->get();
        $prices = [];
        foreach ($lines as $selected) {
            $tld = $catalogues->get($selected->serverId.':'.$selected->tld);
            if (!$tld || $tld->registrar_key !== $selected->registrar) { throw new \DomainException('Unsupported seller TLD.'); }
            $this->requireTld($tld, $selected->action, $selected->term);
            PublicationEligibility::require($tld, $selected->action, $selected->term, true);
            $this->prices->current($selected->serverId, $selected->fingerprint, $selected->tld, 'renew', 1, 'USD', $now, true, $tld);
            $price = $this->prices->current($selected->serverId, $selected->fingerprint, $selected->tld, $selected->action, $selected->term, 'USD', $now, true, $tld);
            if ($price->id !== $selected->priceId || $price->revision !== $selected->priceRevision) { throw new \DomainException('Stale price revision.'); }
            $prices[] = $price;
        }
        return $prices;
    }

    private function account(int $serverId, bool $lock): Account
    {
        $serverQuery = Server::whereKey($serverId);
        if ($lock) { $serverQuery->lockForUpdate(); }
        $server = $serverQuery->firstOrFail();
        $accountQuery = Account::where('server_id', $serverId);
        if ($lock) { $accountQuery->lockForUpdate(); }
        $account = $accountQuery->firstOrFail();
        if (!$server->enabled || $server->extension !== 'Domains') { throw new \DomainException('Seller server is disabled.'); }
        if ($lock) { $this->bindings->requireProcessingLocked($server, $account); return $account; }
        $binding = new \Paymenter\Extensions\Servers\Domains\Data\AccountBinding($account->registrar_key, $serverId,
            \Paymenter\Extensions\Servers\Domains\Data\Environment::from($account->environment), $account->stable_identity);
        $this->bindings->requireProcessing($binding);
        return $account;
    }

    private function tld(Account $account, string $tld, bool $lock): Tld
    {
        $query = Tld::where(['server_id' => $account->server_id, 'registrar_key' => $account->registrar_key,
            'account_fingerprint' => $account->account_fingerprint, 'tld' => $tld]);
        if ($lock) { $query->lockForUpdate(); }
        return $query->firstOrFail();
    }

    private function requireTld(Tld $tld, string $action, int $term): void
    {
        if (!$tld->enabled || !$tld->{$action.'_enabled'} || !$tld->renew_enabled
            || ($action === 'register' && ($term < $tld->min_years || $term > $tld->max_years))) {
            throw new \DomainException('Seller action or term is unavailable.');
        }
    }

    private function requireAction(SalesPolicy $policy, string $action, int $term): void
    {
        if (!in_array($action, ['register', 'transfer'], true) || $term < 1 || $term > 10 || ($action === 'transfer' && $term !== 1)
            || !$policy->server_id || !$policy->{$action.'_enabled'}) { throw new \DomainException('This acquisition is unavailable.'); }
    }
}
