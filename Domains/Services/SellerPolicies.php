<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, UpgradeGate};

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, Environment, SaleSelection};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, SalesPolicy, Tld};

final class SellerPolicies
{
    protected function catalogue(): ?AcquisitionOffers
    { return $this->offers ?? CallerRouting::resolve(AcquisitionOffers::class); }

    public function __construct(private readonly PriceCatalogue $prices, private readonly AccountBindings $bindings, private readonly ?AcquisitionOffers $offers = null) {}

    public function initialize(string $tld, ?int $generation = null): SalesPolicy
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Initialization owns its policy lock order.'); }
        $generation=UpgradeGate::legacy($generation);
        CanonicalName::parse('example.'.$tld, [$tld]);
        return DB::transaction(static function () use ($tld,$generation): SalesPolicy {
            UpgradeGate::lockLegacy($generation);
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
        $generation=UpgradeGate::legacy();
        return DB::transaction(function () use ($tld, $expectedRevision, $serverId, $register, $transfer, $now, $review,$generation): SalesPolicy {
            $review?->lockActor(); $review?->lockAccounts(); $review?->check();
            if ($review && ($review->action !== 'seller' || Tld::findOrFail($review->tldId)->tld !== $tld)) { throw new \DomainException('Seller review mismatch.'); }
            UpgradeGate::lockLegacy($generation);
            $account = $serverId === null ? null : $this->account($serverId, true);
            UpgradeGate::legacy();
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
                    try {
                        PublicationEligibility::firstEligibleTerm($catalogue, $action, function (int $term) use ($catalogue, $action, $serverId, $account, $tld, $now): void {
                            PublicationEligibility::require($catalogue, $action, $term, true);
                            $this->prices->current($serverId, $account->account_fingerprint, $tld, $action, $term, 'USD', $now, true, $catalogue);
                        });
                    }
                    catch (\DomainException) { throw new \DomainException('Seller has no eligible action price.'); }
                }
            }
            $previous = $policy->server_id === null ? null : Account::where('server_id', $policy->server_id)->first();
            $policy->fill(['server_id' => $serverId, 'registrar_key' => $account?->registrar_key, 'account_fingerprint' => $account?->account_fingerprint,
                'register_enabled' => $register, 'transfer_enabled' => $transfer, 'revision' => $policy->revision + 1]);
            SalesPolicy::withPolicyWrite(static fn () => $policy->save());
            if ($previous) { TestCatalogue::withdrawCarts(AccountBindings::binding($previous), $tld); }
            $review?->record();
            return $policy;
        });
    }

    /** Unlocked display lookup only. Every line is rechecked in admit. */
    public function selection(string $tld, string $action, int $term, \DateTimeImmutable $now): SaleSelection
    {
        if ($offers=$this->catalogue()) { return $offers->selection($tld,$action,$term); }
        throw new \DomainException(OfferUpgrade::MESSAGE);
    }



    /** One display invocation only. Account validation is shared across its selected endings, never admission. */
    public function displayCatalogues(array $endings): array
    {
        if ($offers=$this->catalogue()) {
            $out=[];
            foreach ($offers->display($endings) as $name=>$actions) {
                $policy=SalesPolicy::where('tld',$name)->firstOrFail();$account=Account::where('server_id',$policy->server_id)->sole();
                $out[$name]=[$policy,$account,Tld::where(['server_id'=>$account->server_id,'tld'=>$name])->firstOrFail()];
            }
            return $out;
        }
        throw new \DomainException(OfferUpgrade::MESSAGE);
    }



    /** Join native checkout AFTER user/coupon/product locks. No nested transaction or per-line commit.
     * Caller binds ALL invoice/service/reservation/operation writes before its outer commit.
     * @param non-empty-list<SaleSelection> $lines @return list<\Paymenter\Extensions\Servers\Domains\Data\PriceSnapshot> input order
     */
    public function admit(array $lines, \DateTimeImmutable $now): array
    {
        if ($offers=$this->catalogue()) { return array_column($offers->admit($lines),'snapshot'); }
        throw new \DomainException(OfferUpgrade::MESSAGE);
    }



    /** Staff can curate a seller before Activate; customer selection and locked admission cannot sell yet. */


    private function account(int $serverId, bool $lock): Account
    {
        $serverQuery = Server::whereKey($serverId);
        if ($lock) { $serverQuery->lockForUpdate(); }
        $server = $serverQuery->firstOrFail();
        $accountQuery = Account::where('server_id', $serverId);
        if ($lock) { $accountQuery->lockForUpdate(); }
        $account = $accountQuery->firstOrFail();
        if (!$server->enabled || $server->extension !== 'Domains') { throw new \DomainException('Seller server is disabled.'); }
        if (TestCatalogue::enabled(AccountBindings::binding($account))) {
            if ($lock) { $this->bindings->requireReadableLocked($server, $account); }
            elseif (!$this->bindings->configurationAvailable($serverId)) { throw new \DomainException('Catalogue account unavailable.'); }
            return $account;
        }
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




}
