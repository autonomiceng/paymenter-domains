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



    /** No provider reads under locks; processing/configuration guard is local. Switch affects future orders only. */


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









}
