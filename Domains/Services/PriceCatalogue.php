<?php
// Cost refresh/publication adapted from Enom 444f31c Services/Catalogue.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, UpgradeGate};

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{PriceSnapshot, RenewalPrice};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};

final class PriceCatalogue
{
    /** Daily sync plus one missed run. This is our sales policy, never a registrar price lock. */
    public const MAX_COST_AGE_SECONDS = 172800;
    protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts
    { return $this->reads ?? CallerRouting::resolve(\Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts::class); }

    public function __construct(private readonly PricingRules $rules, private readonly ?\Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities $offers = null,
        private readonly ?\Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts $reads = null) {}

    /** Cost-only legacy import: deliberately drops publication proof on touched rows. Reads finish before locking. Non-selling TLDs retain holder cost data; native-disabled servers are read-only.
     * Callback returns explicit rate/no-price entries; omissions NEVER withdraw existing terms.
     */


    /** Shared batch import and existing native publication commit together, in native lock order. */




    /** Caller owns native publication prefix, server/account, policy and TLD locks. */


    /** Quarantine an explicit malformed row, preserving holder retail and price identities. */


    /** Published annual retail survives unknown/stale costs and rule republication failures.
     * Slice 24 projects this into native service.price under locks; issued invoices remain frozen.
     */
    public function renewal(int $serverId, string $fingerprint, string $tld, string $currency, \DateTimeImmutable $now, bool $lock = false, ?Tld $locked = null): RenewalPrice
    {
        if ($caps = $this->offers ?? \Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::routed()) {
            if ($currency !== 'USD') { throw new \DomainException('USD holder retail required.'); }
            $b = AccountBindings::binding(Account::where('server_id',$serverId)->sole());
            if ($b->fingerprint !== $fingerprint) { throw new \DomainException('Holder binding changed.'); }
            return $caps->holder($b,$tld,$lock ? ($locked ?? throw new \LogicException('Locked holding TLD required.')) : null);
        }
        throw new \DomainException(OfferUpgrade::MESSAGE);
    }



    /** Fresh cost evidence only; does not check seller, ownership, funding or driver eligibility.
     * lock=true requires caller-held server, account, policy and TLD locks in storage.md order.
     */
    public function current(int $serverId, string $fingerprint, string $tld, string $action, int $term,
        string $currency, \DateTimeImmutable $now, bool $lock = false, ?Tld $locked = null): PriceSnapshot
    {
        if($ports=$this->catalogue()){
            if($lock&&(DB::transactionLevel()===0||!$locked||(int)$locked->server_id!==$serverId||$locked->tld!==$tld||$locked->account_fingerprint!==$fingerprint)){throw new \LogicException('Locked price validation requires its caller-held TLD.');}
            $b=AccountBindings::binding(Account::where('server_id',$serverId)->sole());
            if($b->fingerprint!==$fingerprint){throw new \DomainException('Price account binding changed.');}
            $q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($b,[$tld],$action,$term,$currency);
            return $ports->loadBatch([$q],\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Holder,$lock)[0][0]->costPrice($now);
        }
        throw new \DomainException(OfferUpgrade::MESSAGE);
    }



    /** Pure saved-price validation for render snapshots; callers qualify the row's binding and action.
     * Saved costs stop authorizing new sales after the daily sync and one missed run. */






    /** Term retail for one rule and its margin decision. Publication and the staff preview share it.
     * Renewals that cannot be published (unknown or nonpositive price) keep the prior retail; below-cost renewals are
     * still billed; below-cost registrations and transfers are held. */


    /** Saved rows a staff price change republishes, in register, renew, transfer order. */


    /** A saved price is on an older rule, and every cost needed to redo it is here. */


    /** A saved price is on an older rule, whether or not it can be redone yet. */


    /** What saving a rule would publish: cost, current and new term retail, without writing. */


    /** Staff saves publish at once, so every saved term needs a synced read and the one-year renewal needs a cost.
     * A read without a cost means the registrar does not offer that term. Old reads still count. */




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
